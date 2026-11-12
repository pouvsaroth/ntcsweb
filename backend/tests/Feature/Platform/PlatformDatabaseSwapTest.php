<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\DataOps\PgDatabases;
use App\Platform\DataOps\PgDump;
use App\Platform\Jobs\SwapSchoolDatabase;
use App\Platform\Models\Backup;
use App\Platform\Models\DatabaseSwap;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolDatabase;
use App\Services\Tenancy\TenantProvisioningService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakePgDump;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Data ops > Databases > Use for a school: a restored database put in place of a school's own. */
class PlatformDatabaseSwapTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    /** @var object{names: list<string>, renames: list<array{0: string, 1: string}>, rows: array<string, int>} */
    private object $server;

    private Tenant $school;

    private string $live;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('backups');

        $database = new FakeSchoolDatabase;
        $database->existing = [(string) config('database.connections.pgsql.database')];
        $this->app->instance(SchoolDatabase::class, $database);
        $this->app->instance(PgDump::class, new FakePgDump);

        $this->server = new class(app(TenantProvisioningService::class)) extends PgDatabases
        {
            public array $names = [];

            public array $renames = [];

            /** @var array<string, int> database => this school's rows */
            public array $rows = [];

            public function exists(string $name): bool
            {
                return in_array($name, $this->names, true);
            }

            public function rename(string $from, string $to): void
            {
                $this->renames[] = [$from, $to];
                $this->names = array_values(array_diff($this->names, [$from]));
                $this->names[] = $to;
                if (array_key_exists($from, $this->rows)) {
                    $this->rows[$to] = $this->rows[$from];
                    unset($this->rows[$from]);
                }
            }

            public function schoolRows(string $database, int $tenantId): int
            {
                return $this->rows[$database] ?? 0;
            }

            public int $migrated = 0;

            public ?string $migrateFails = null;

            public function migrateSchool(Tenant $tenant): void
            {
                $this->migrated++;
                if ($this->migrateFails !== null) {
                    throw new \RuntimeException($this->migrateFails);
                }
            }

            public function drop(string $name): void
            {
                $this->names = array_values(array_diff($this->names, [$name]));
            }
        };
        $this->app->instance(PgDatabases::class, $this->server);


        $this->school = Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha']);
        $this->school->forceFill(['status' => Tenant::STATUS_ACTIVE])->save();
        $this->live = (string) SchoolPlacement::of($this->school)->database;
        $database->existing[] = $this->live;

        $this->server->names = [$this->live, 'alpha_r1'];
        $this->server->rows = ['alpha_r1' => 12, $this->live => 30];
        ExtraDatabase::query()->create(['name' => 'alpha_r1', 'source' => 'backup', 'source_database' => $this->live, 'status' => 'ready']);
        $this->actingAsStaff($this->platformStaff());
    }

    private function useFor(string $name = 'alpha_r1', ?string $confirm = null): \Illuminate\Testing\TestResponse
    {
        $id = ExtraDatabase::query()->where('name', $name)->value('id');

        return $this->postJson("/api/v1/platform/data/extra-databases/{$id}/use-for-school", ['tenant_id' => $this->school->id, 'confirm_name' => $confirm ?? $this->live]);
    }

    private function runSwap(): DatabaseSwap
    {
        $swap = DatabaseSwap::query()->latest('id')->firstOrFail();
        (new SwapSchoolDatabase($swap->id))->handle(app(\App\Platform\DataOps\DatabaseSwapper::class));

        return $swap->fresh();
    }

    public function test_a_restored_database_takes_the_school_place_and_the_old_one_is_kept(): void
    {
        $kept = $this->live.'_before_restore';
        $this->useFor()->assertCreated()->assertJsonPath('data.status', 'queued')->assertJsonPath('data.kept_as', $kept);
        Queue::assertPushed(SwapSchoolDatabase::class);

        $swap = $this->runSwap();

        $this->assertSame('done', $swap->status, (string) $swap->error);
        $this->assertSame([[$this->live, $kept], ['alpha_r1', $this->live]], $this->server->renames);
        $this->assertSame(['check', 'suspend', 'backup', 'swap', 'migrate', 'reactivate'], array_column($swap->steps, 'step'));
        $this->assertSame(1, $this->server->migrated);

        $backup = Backup::query()->findOrFail($swap->backup_id);
        $this->assertSame(['restore', 'dump', 'ok', $this->live], [$backup->trigger, $backup->format, $backup->status, $backup->database]);

        $this->assertSame(Tenant::STATUS_ACTIVE, $this->school->fresh()->status);
        $this->assertNull(ExtraDatabase::query()->where('name', 'alpha_r1')->first());
        $old = ExtraDatabase::query()->where('name', $kept)->sole();
        $this->assertSame(['replaced', $this->live, 'ready'], [$old->source, $old->source_database, $old->status]);

        // The swaps list shows it.
        $this->getJson('/api/v1/platform/data/database-swaps')->assertOk()->assertJsonPath('data.0.status', 'done')->assertJsonPath('data.0.school.name', 'Alpha');
    }

    public function test_putting_the_kept_copy_back_trades_the_two_places(): void
    {
        $kept = $this->live.'_before_restore';
        $this->useFor()->assertCreated();
        $this->runSwap();
        $this->server->renames = [];

        $this->useFor($kept)->assertCreated();
        $swap = $this->runSwap();

        $this->assertSame('done', $swap->status, (string) $swap->error);
        $parked = $this->live.'_swap_park';
        $this->assertSame([[$this->live, $parked], [$kept, $this->live], [$parked, $kept]], $this->server->renames);
        $this->assertEqualsCanonicalizing([$this->live, $kept], $this->server->names);
        $this->assertSame(1, ExtraDatabase::query()->where('name', $kept)->count());
    }

    public function test_a_failed_migrate_puts_both_names_back_and_reopens_the_school(): void
    {
        $this->server->migrateFails = 'Migration 2026_x failed';
        $this->useFor()->assertCreated();

        $swap = $this->runSwap();

        $kept = $this->live.'_before_restore';
        $this->assertSame('failed', $swap->status);
        $this->assertStringContainsString('2026_x', (string) $swap->error);
        $this->assertSame([[$this->live, $kept], ['alpha_r1', $this->live], [$this->live, 'alpha_r1'], [$kept, $this->live]], $this->server->renames);
        $this->assertEqualsCanonicalizing([$this->live, 'alpha_r1'], $this->server->names);
        $this->assertSame(Tenant::STATUS_ACTIVE, $this->school->fresh()->status);
        $this->assertNotNull(ExtraDatabase::query()->where('name', 'alpha_r1')->first());
    }

    public function test_a_database_without_this_school_data_is_refused_before_anything_changes(): void
    {
        $this->server->rows = ['alpha_r1' => 0];
        $this->useFor()->assertCreated();

        $swap = $this->runSwap();

        $this->assertSame('failed', $swap->status);
        $this->assertStringContainsString('holds no data of Alpha', (string) $swap->error);
        $this->assertSame([], $this->server->renames);
        $this->assertSame(['check'], array_column($swap->steps, 'step'));
        $this->assertSame(0, Backup::query()->count());
    }

    public function test_requests_are_checked(): void
    {
        // The school's database name must be typed.
        $this->useFor(confirm: 'alpha')->assertUnprocessable()->assertJsonValidationErrors('confirm_name');

        // Not while it is still being made.
        ExtraDatabase::query()->create(['name' => 'alpha_r2', 'source' => 'backup', 'status' => 'creating']);
        $this->server->names[] = 'alpha_r2';
        $this->useFor('alpha_r2')->assertUnprocessable()->assertJsonValidationErrors('database');

        // The copy kept from the last restore must be deleted first.
        $this->server->names[] = $this->live.'_before_restore';
        $this->useFor()->assertUnprocessable()->assertJsonValidationErrors('tenant_id');
        $this->server->names = [$this->live, 'alpha_r1'];

        // A school in the shared database has no database of its own to swap.
        SchoolPlacement::query()->updateOrCreate(['tenant_id' => $this->school->id], ['mode' => SchoolPlacement::SHARED, 'database' => 'tn_shared']);
        $this->postJson('/api/v1/platform/data/extra-databases/'.ExtraDatabase::query()->where('name', 'alpha_r1')->value('id').'/use-for-school', ['tenant_id' => $this->school->id, 'confirm_name' => 'tn_shared'])
            ->assertUnprocessable()->assertJsonValidationErrors('tenant_id');

        Queue::assertNothingPushed();
        $this->assertSame(0, DatabaseSwap::query()->count());
    }

    public function test_it_needs_the_databases_permission(): void
    {
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.backup']));

        $this->useFor()->assertNotFound();
        $this->getJson('/api/v1/platform/data/database-swaps')->assertOk();
        Queue::assertNothingPushed();
    }
}
