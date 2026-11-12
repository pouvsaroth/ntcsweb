<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\DataOps\PgDatabases;
use App\Platform\DataOps\PgDump;
use App\Platform\Jobs\CreateExtraDatabase;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\FakePgDump;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Data ops > Databases: "Other" databases staff make (empty / template / copy) and delete. */
class PlatformExtraDatabasesTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private FakePgDump $pgDump;

    /** @var object{names: list<string>, created: array<string, string>, dropped: list<string>} */
    private object $server;

    private string $central;

    private FakeSchoolDatabase $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->central = (string) config('database.connections.pgsql.database');
        $database = new class extends FakeSchoolDatabase
        {
            public function sizeOf(string $name): ?int
            {
                return $this->exists($name) ? 2048 : null;
            }
        };
        $database->existing = [$this->central, 'tn_alpha'];
        $this->database = $database;
        $this->app->instance(SchoolDatabase::class, $database);
        $this->pgDump = new FakePgDump;
        $this->app->instance(PgDump::class, $this->pgDump);

        // The server's databases, in memory.
        $this->server = new class(app(\App\Services\Tenancy\TenantProvisioningService::class)) extends PgDatabases
        {
            public array $names = [];

            public array $created = [];

            public array $dropped = [];

            public function exists(string $name): bool
            {
                return in_array($name, $this->names, true);
            }

            public function createEmpty(string $name): void
            {
                $this->names[] = $name;
                $this->created[$name] = 'empty';
            }

            public function cloneTemplate(string $name): void
            {
                $this->names[] = $name;
                $this->created[$name] = 'template';
            }

            public function drop(string $name): void
            {
                $this->names = array_values(array_diff($this->names, [$name]));
                $this->dropped[] = $name;
            }
        };
        $this->server->names = [$this->central, 'tn_alpha'];
        $this->app->instance(PgDatabases::class, $this->server);

        Tenant::factory()->create(['slug' => 'alpha', 'name' => 'Alpha'])->forceFill(['status' => Tenant::STATUS_ACTIVE])->save();
    }

    private function asOwner(): static
    {
        return $this->actingAsStaff($this->platformStaff());
    }

    public function test_an_empty_and_a_template_database_are_made_in_the_background(): void
    {
        Queue::fake();
        $this->asOwner();

        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'scratch_db', 'source' => 'empty'])->assertCreated()->assertJsonPath('data.status', 'creating');
        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'school_blank', 'source' => 'template'])->assertCreated();
        Queue::assertPushed(CreateExtraDatabase::class, 2);

        foreach (ExtraDatabase::query()->get() as $row) {
            (new CreateExtraDatabase($row->id))->handle(app(\App\Platform\DataOps\DatabaseMaker::class));
        }
        $this->assertSame(['scratch_db' => 'empty', 'school_blank' => 'template'], $this->server->created);
        $this->database->existing = [...$this->database->existing, 'scratch_db', 'school_blank'];

        $rows = collect($this->getJson('/api/v1/platform/data/databases')->assertOk()->json('data'))->keyBy('database');
        $this->assertSame('extra', $rows['scratch_db']['kind']);
        $this->assertSame('ready', $rows['scratch_db']['extra']['status']);
        $this->assertSame([], $rows['scratch_db']['flags']);
        $this->assertNull($rows['scratch_db']['pending_migrations']);
        $this->assertSame('template', $rows['school_blank']['extra']['source']);
    }

    public function test_a_copy_is_dumped_and_restored_into_the_new_name(): void
    {
        Queue::fake();
        $this->asOwner();

        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'alpha_test', 'source' => 'copy', 'source_database' => 'tn_alpha'])->assertCreated();
        $row = ExtraDatabase::query()->sole();
        (new CreateExtraDatabase($row->id))->handle(app(\App\Platform\DataOps\DatabaseMaker::class));

        $this->assertSame(['tn_alpha'], $this->pgDump->dumped);
        $this->assertStringContainsString('fake dump of tn_alpha', $this->pgDump->restored['alpha_test']);
        $this->assertSame('ready', $row->fresh()->status);
        $this->assertSame([], glob(storage_path('app/tmp/copy-alpha_test-*')));
    }

    public function test_a_failed_make_is_recorded_and_leaves_nothing_behind(): void
    {
        Queue::fake();
        $this->asOwner();
        $this->pgDump->failRestoreFor['alpha_copy'] = 'pg_restore: error: out of disk';

        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'alpha_copy', 'source' => 'copy', 'source_database' => 'tn_alpha'])->assertCreated();
        $row = ExtraDatabase::query()->sole();
        $this->server->names[] = 'alpha_copy'; // restoreInto had created it before failing
        (new CreateExtraDatabase($row->id))->handle(app(\App\Platform\DataOps\DatabaseMaker::class));

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertStringContainsString('out of disk', (string) $row->fresh()->error);
        $this->assertContains('alpha_copy', $this->server->dropped);
    }

    public function test_names_and_sources_are_checked(): void
    {
        Queue::fake();
        $this->asOwner();
        $this->server->names[] = 'someone_elses';

        foreach (['Bad Name', '9start', 'ab', 'tn_alpha', $this->central, 'postgres', 'someone_elses'] as $name) {
            $this->postJson('/api/v1/platform/data/extra-databases', ['name' => $name, 'source' => 'empty'])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'copy_x', 'source' => 'copy', 'source_database' => 'not_ours'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_database');
        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'copy_x', 'source' => 'copy'])
            ->assertUnprocessable()->assertJsonValidationErrors('source_database');
        Queue::assertNothingPushed();
    }

    public function test_deleting_needs_the_typed_name_and_a_finished_unused_database(): void
    {
        $this->asOwner();
        $ready = ExtraDatabase::query()->create(['name' => 'old_copy', 'source' => 'empty', 'status' => 'ready']);
        $busy = ExtraDatabase::query()->create(['name' => 'in_progress', 'source' => 'empty', 'status' => 'creating']);
        $this->server->names[] = 'old_copy';

        $this->deleteJson("/api/v1/platform/data/extra-databases/{$ready->id}", ['confirm_name' => 'old'])->assertUnprocessable()->assertJsonValidationErrors('confirm_name');
        $this->deleteJson("/api/v1/platform/data/extra-databases/{$busy->id}", ['confirm_name' => 'in_progress'])->assertUnprocessable();

        $this->deleteJson("/api/v1/platform/data/extra-databases/{$ready->id}", ['confirm_name' => 'old_copy'])->assertNoContent();
        $this->assertSame(['old_copy'], $this->server->dropped);
        $this->assertNull(ExtraDatabase::query()->find($ready->id));
    }

    public function test_it_needs_the_databases_permission(): void
    {
        Queue::fake();
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.backup', 'platform.data.migrate', 'platform.data.edit']));

        // The back office answers 404 to an action the staff member may not take.
        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'scratch_db', 'source' => 'empty'])->assertNotFound();
        Queue::assertNothingPushed();
    }
}
