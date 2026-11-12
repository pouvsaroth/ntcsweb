<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\DataOps\DatabaseMaker;
use App\Platform\DataOps\PgDatabases;
use App\Platform\DataOps\PgDump;
use App\Platform\Jobs\CreateExtraDatabase;
use App\Platform\Models\Backup;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakePgDump;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/** Data ops > Databases: "Other" databases staff make (empty / template / copy / restored) and delete. */
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

    public function test_a_good_backup_is_restored_into_a_new_database(): void
    {
        Queue::fake();
        Storage::fake('backups');
        Storage::disk('backups')->put('alpha/one.dump', 'PGDMP backup of tn_alpha');
        $backup = Backup::query()->forceCreate(['database' => 'tn_alpha', 'format' => Backup::FORMAT_DUMP, 'trigger' => 'manual', 'status' => Backup::STATUS_OK, 'path' => 'alpha/one.dump']);
        $this->asOwner();

        $this->postJson('/api/v1/platform/data/extra-databases/restore', ['name' => 'alpha_restored', 'source' => 'backup', 'backup_id' => $backup->id])->assertCreated();
        $row = ExtraDatabase::query()->sole();
        $this->assertSame($backup->id, $row->backup_id);
        $this->assertSame('tn_alpha', $row->source_database);
        (new CreateExtraDatabase($row->id))->handle(app(DatabaseMaker::class));

        $this->assertSame('PGDMP backup of tn_alpha', $this->pgDump->restored['alpha_restored']);
        $this->assertSame('ready', $row->fresh()->status);
        Storage::disk('backups')->assertExists('alpha/one.dump'); // the backup itself is kept
    }

    public function test_only_finished_database_backups_can_be_restored(): void
    {
        Queue::fake();
        Storage::fake('backups');
        Storage::disk('backups')->put('x.zip', 'zip');
        $this->asOwner();

        $school = Backup::query()->forceCreate(['database' => 'tn_alpha', 'format' => Backup::FORMAT_SCHOOL, 'trigger' => 'manual', 'status' => 'ok', 'path' => 'x.zip']);
        $failed = Backup::query()->forceCreate(['database' => 'tn_alpha', 'format' => Backup::FORMAT_DUMP, 'trigger' => 'manual', 'status' => 'failed', 'path' => null]);
        $gone = Backup::query()->forceCreate(['database' => 'tn_alpha', 'format' => Backup::FORMAT_DUMP, 'trigger' => 'manual', 'status' => 'ok', 'path' => 'missing.dump']);

        foreach ([$school, $failed, $gone] as $backup) {
            $this->postJson('/api/v1/platform/data/extra-databases/restore', ['name' => 'try_restore', 'source' => 'backup', 'backup_id' => $backup->id])
                ->assertUnprocessable()->assertJsonValidationErrors('backup_id');
        }
        $this->postJson('/api/v1/platform/data/extra-databases/restore', ['name' => 'tn_alpha', 'source' => 'backup', 'backup_id' => $gone->id])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        Queue::assertNothingPushed();
    }

    public function test_an_uploaded_dump_is_restored_and_the_file_removed(): void
    {
        Queue::fake();
        $this->asOwner();
        $file = UploadedFile::fake()->createWithContent('from-laptop.dump', 'PGDMP uploaded data');

        $this->post('/api/v1/platform/data/extra-databases/restore', ['name' => 'from_laptop', 'source' => 'upload', 'file' => $file], ['Accept' => 'application/json'])->assertCreated();
        $row = ExtraDatabase::query()->sole();
        $this->assertSame('from-laptop.dump', $row->upload_name);
        $kept = app(DatabaseMaker::class)->uploadsDir().'/'.$row->upload_path;
        $this->assertFileExists($kept);

        (new CreateExtraDatabase($row->id))->handle(app(DatabaseMaker::class));

        $this->assertSame('PGDMP uploaded data', $this->pgDump->restored['from_laptop']);
        $this->assertSame('ready', $row->fresh()->status);
        $this->assertNull($row->fresh()->upload_path);
        $this->assertFileDoesNotExist($kept);
    }

    public function test_a_failed_upload_restore_still_removes_the_file(): void
    {
        Queue::fake();
        $this->asOwner();
        $this->pgDump->failRestoreFor['bad_upload'] = 'pg_restore: error: input file is too short';

        $this->post('/api/v1/platform/data/extra-databases/restore', ['name' => 'bad_upload', 'source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('x.dump', 'PGDMP x')], ['Accept' => 'application/json'])->assertCreated();
        $row = ExtraDatabase::query()->sole();
        $kept = app(DatabaseMaker::class)->uploadsDir().'/'.$row->upload_path;
        (new CreateExtraDatabase($row->id))->handle(app(DatabaseMaker::class));

        $this->assertSame('failed', $row->fresh()->status);
        $this->assertStringContainsString('too short', (string) $row->fresh()->error);
        $this->assertFileDoesNotExist($kept);
    }

    public function test_an_upload_that_is_not_a_dump_is_refused(): void
    {
        Queue::fake();
        $this->asOwner();

        $this->post('/api/v1/platform/data/extra-databases/restore', ['name' => 'plain_sql', 'source' => 'upload', 'file' => UploadedFile::fake()->createWithContent('db.sql', 'CREATE TABLE x ();')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->post('/api/v1/platform/data/extra-databases/restore', ['name' => 'plain_sql', 'source' => 'upload'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, ExtraDatabase::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_it_needs_the_databases_permission(): void
    {
        Queue::fake();
        $this->actingAsStaff($this->platformStaff(['platform.data.read', 'platform.data.backup', 'platform.data.migrate', 'platform.data.edit']));

        // The back office answers 404 to an action the staff member may not take.
        $this->postJson('/api/v1/platform/data/extra-databases', ['name' => 'scratch_db', 'source' => 'empty'])->assertNotFound();
        $this->postJson('/api/v1/platform/data/extra-databases/restore', ['name' => 'scratch_db', 'source' => 'backup', 'backup_id' => 1])->assertNotFound();
        Queue::assertNothingPushed();
    }
}
