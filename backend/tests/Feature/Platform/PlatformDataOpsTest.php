<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Tenant;
use App\Platform\DataOps\PgDump;
use App\Platform\Models\Backup;
use App\Platform\Models\BackupSchedule;
use App\Platform\Models\MigrationRun;
use App\Platform\Services\SchoolDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\FakePgDump;
use Tests\Concerns\FakeSchoolDatabase;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

class PlatformDataOpsTest extends TestCase
{
    use HasPlatformStaff, RefreshDatabase;

    private FakeSchoolDatabase $database;

    private FakePgDump $pgDump;

    private string $central;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Storage::fake('backups-copy');
        config(['platform.backup_copy_disk' => 'backups-copy']);

        $this->database = new class extends FakeSchoolDatabase
        {
            public function sizeOf(string $name): ?int
            {
                return $this->exists($name) ? 1024 : null;
            }

            public function appliedMigrations(\App\Models\Tenant $tenant): array
            {
                // Everything but the newest tenant migration: one pending.
                $files = array_map(fn ($f) => basename($f, '.php'), glob(database_path('migrations/tenant/*.php')));
                sort($files);

                return array_slice($files, 0, -1);
            }
        };
        $this->pgDump = new FakePgDump;
        $this->app->instance(SchoolDatabase::class, $this->database);
        $this->app->instance(PgDump::class, $this->pgDump);

        $this->central = (string) config('database.connections.pgsql.database');
        $this->database->existing = [$this->central];
    }

    private function school(string $slug, string $status = Tenant::STATUS_ACTIVE, bool $withDatabase = true): Tenant
    {
        $tenant = Tenant::factory()->create(['slug' => $slug, 'name' => ucfirst($slug)]);
        $tenant->forceFill(['status' => $status])->save();
        if ($withDatabase) {
            $this->database->existing[] = 'tn_'.$slug;
        }

        return $tenant;
    }

    public function test_the_databases_screen_lists_central_and_every_school_with_flags(): void
    {
        $this->school('alpha');
        $this->school('beta', withDatabase: false);
        $this->school('gamma', Tenant::STATUS_ARCHIVED, withDatabase: false);

        $rows = collect($this->actingAsStaff($this->platformStaff())->getJson('/api/v1/platform/data/databases')->assertOk()->json('data'))->keyBy('database');

        $this->assertSame('central', $rows[$this->central]['kind']);
        $this->assertTrue($rows['tn_alpha']['exists']);
        $this->assertSame(1, $rows['tn_alpha']['pending_migrations']);
        $this->assertContains('behind', $rows['tn_alpha']['flags']);
        $this->assertContains('no_recent_backup', $rows['tn_alpha']['flags']);
        $this->assertSame(['missing'], $rows['tn_beta']['flags']);
        // Archived: not expected to have a database, so not "missing".
        $this->assertSame([], $rows['tn_gamma']['flags']);
    }

    public function test_back_up_now_writes_the_file_records_it_and_copies_it(): void
    {
        $this->school('alpha');
        $staff = $this->platformStaff();

        $this->actingAsStaff($staff)->postJson('/api/v1/platform/data/backups', ['database' => 'tn_alpha'])->assertCreated();

        $backup = Backup::query()->sole();
        $this->assertSame('ok', $backup->status);
        $this->assertSame($staff->id, $backup->staff_id);
        Storage::disk('backups')->assertExists($backup->path);
        Storage::disk('backups-copy')->assertExists($backup->path);
        $this->assertNotNull($backup->copied_at);
        $this->assertStringStartsWith('tn_alpha/tn_alpha_', $backup->path);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'backup.started']);

        // Now it counts as recent.
        $row = collect($this->getJson('/api/v1/platform/data/databases')->json('data'))->firstWhere('database', 'tn_alpha');
        $this->assertNotContains('no_recent_backup', $row['flags']);
    }

    public function test_back_up_all_covers_every_existing_database_and_a_failure_is_recorded(): void
    {
        $this->school('alpha');
        $this->school('beta');
        $this->school('nodb', withDatabase: false);
        $this->pgDump->failFor['tn_beta'] = 'pg_dump: error: connection to server failed';

        $this->actingAsStaff($this->platformStaff())->postJson('/api/v1/platform/data/backups', ['database' => 'all'])->assertCreated();

        $this->assertEqualsCanonicalizing([$this->central, 'tn_alpha'], $this->pgDump->dumped);
        $failed = Backup::query()->where('database', 'tn_beta')->sole();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('pg_dump: error: connection to server failed', $failed->error);
        $this->assertNull($failed->path);
        $this->assertSame([], Storage::disk('backups')->files('tn_beta'));
        $this->assertSame(0, Backup::query()->where('database', 'tn_nodb')->count());
    }

    public function test_unknown_or_missing_databases_are_refused(): void
    {
        $this->school('nodb', withDatabase: false);
        $this->actingAsStaff($this->platformStaff());

        $this->postJson('/api/v1/platform/data/backups', ['database' => 'postgres'])->assertUnprocessable();
        $this->postJson('/api/v1/platform/data/backups', ['database' => 'tn_nodb'])->assertUnprocessable();
    }

    public function test_a_copy_failure_does_not_fail_the_backup(): void
    {
        $this->school('alpha');
        config(['platform.backup_copy_disk' => 'no-such-disk']);

        $this->actingAsStaff($this->platformStaff())->postJson('/api/v1/platform/data/backups', ['database' => 'tn_alpha'])->assertCreated();

        $backup = Backup::query()->sole();
        $this->assertSame('ok', $backup->status);
        $this->assertNull($backup->copied_at);
        $this->assertNotNull($backup->copy_error);
    }

    public function test_download_and_delete_are_audited(): void
    {
        $this->school('alpha');
        $this->actingAsStaff($this->platformStaff())->postJson('/api/v1/platform/data/backups', ['database' => 'tn_alpha']);
        $backup = Backup::query()->sole();

        $this->get("/api/v1/platform/data/backups/{$backup->id}/download")->assertOk()->assertDownload(basename($backup->path));
        $this->deleteJson("/api/v1/platform/data/backups/{$backup->id}")->assertNoContent();

        Storage::disk('backups')->assertMissing($backup->path);
        Storage::disk('backups-copy')->assertMissing($backup->path);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'backup.downloaded']);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'backup.deleted']);
    }

    public function test_the_schedule_runs_once_per_slot_on_scheduled_days_only(): void
    {
        $this->school('alpha');
        BackupSchedule::current()->forceFill(['days' => ['mon', 'wed'], 'at' => '02:00', 'last_run_at' => null])->save();

        // Monday 2026-10-19, 01:59 Phnom Penh — not yet.
        Carbon::setTestNow(Carbon::parse('2026-10-19 01:59', 'Asia/Phnom_Penh'));
        $this->artisan('platform:backups-tick')->assertSuccessful();
        $this->assertSame(0, Backup::query()->count());

        // 02:00 — due: one backup per existing database.
        Carbon::setTestNow(Carbon::parse('2026-10-19 02:00', 'Asia/Phnom_Penh'));
        $this->artisan('platform:backups-tick')->assertSuccessful();
        $this->assertSame(2, Backup::query()->where('trigger', 'scheduled')->count());

        // The next minute: the slot is taken.
        Carbon::setTestNow(Carbon::parse('2026-10-19 02:01', 'Asia/Phnom_Penh'));
        $this->artisan('platform:backups-tick')->assertSuccessful();
        $this->assertSame(2, Backup::query()->count());

        // Tuesday is not a scheduled day.
        Carbon::setTestNow(Carbon::parse('2026-10-20 03:00', 'Asia/Phnom_Penh'));
        $this->artisan('platform:backups-tick')->assertSuccessful();
        $this->assertSame(2, Backup::query()->count());

        $this->assertDatabaseHas('bo_heartbeats', ['name' => 'scheduler']);
        Carbon::setTestNow();
    }

    public function test_retention_removes_old_backups_but_never_the_newest_good_one(): void
    {
        $this->school('alpha');
        $this->school('beta');
        $this->actingAsStaff($this->platformStaff());

        $make = function (string $db, string $status, int $daysAgo): Backup {
            $path = "{$db}/{$db}_{$daysAgo}.dump";
            Storage::disk('backups')->put($path, 'x');

            return Backup::query()->forceCreate([
                'database' => $db, 'trigger' => 'scheduled', 'status' => $status,
                'path' => $status === 'ok' ? $path : null,
                'created_at' => now()->subDays($daysAgo), 'finished_at' => now()->subDays($daysAgo),
            ]);
        };

        $alphaOld = $make('tn_alpha', 'ok', 30);
        $alphaNew = $make('tn_alpha', 'ok', 2);
        $alphaFailed = $make('tn_alpha', 'failed', 20);
        $betaOnly = $make('tn_beta', 'ok', 40); // old, but beta's only good backup

        BackupSchedule::current()->forceFill(['enabled' => false, 'keep_days' => 14])->save();
        $this->artisan('platform:backups-tick')->assertSuccessful();

        $this->assertNull(Backup::query()->find($alphaOld->id));
        $this->assertNull(Backup::query()->find($alphaFailed->id));
        $this->assertNotNull(Backup::query()->find($alphaNew->id));
        $this->assertNotNull(Backup::query()->find($betaOnly->id));
        Storage::disk('backups')->assertMissing($alphaOld->path);
        Storage::disk('backups')->assertExists($betaOnly->path);
    }

    public function test_the_schedule_can_be_changed_and_is_audited(): void
    {
        $this->actingAsStaff($this->platformStaff());

        $this->putJson('/api/v1/platform/data/backup-schedule', ['enabled' => true, 'days' => ['sun'], 'at' => '25:00', 'keep_days' => 7])->assertUnprocessable();
        $this->putJson('/api/v1/platform/data/backup-schedule', ['enabled' => true, 'days' => ['sun'], 'at' => '03:30', 'keep_days' => 7])
            ->assertOk()
            ->assertJsonPath('data.days', ['sun'])
            ->assertJsonPath('data.at', '03:30')
            ->assertJsonPath('data.copy_disk', 'backups-copy');

        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'backup.schedule_changed']);
    }

    public function test_migrate_all_runs_each_school_and_one_failure_does_not_stop_the_rest(): void
    {
        $this->school('alpha');
        $this->school('beta');
        $this->school('nodb', withDatabase: false);
        $this->database->failTimes = 1; // the first school's migrate throws

        $this->actingAsStaff($this->platformStaff())->postJson('/api/v1/platform/data/migrate', ['tenant_id' => 'all'])
            ->assertCreated()
            ->assertJsonPath('data.count', 2);

        $runs = MigrationRun::query()->with('tenant')->orderBy('id')->get();
        $this->assertSame(['failed', 'ok'], $runs->pluck('status')->all());
        $this->assertSame('could not connect to server: Connection refused', $runs[0]->error);
        $this->assertSame(1, $runs->pluck('batch')->unique()->count());
        $this->assertSame(['alpha', 'beta'], $runs->map(fn ($r) => $r->tenant->slug)->all());
    }

    public function test_create_database_for_a_school_that_has_none_leaves_status_and_owner_alone(): void
    {
        $tenant = $this->school('nodb', withDatabase: false);
        $this->actingAsStaff($this->platformStaff());

        $this->postJson("/api/v1/platform/data/schools/{$tenant->id}/create-database")->assertCreated();

        $run = MigrationRun::query()->sole();
        $this->assertSame('ok', $run->status, (string) $run->error);
        $this->assertSame(['nodb'], $this->database->created);
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->fresh()->status);
        $this->assertSame(4, \App\Models\Role::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        // Now it has one: refused.
        $this->postJson("/api/v1/platform/data/schools/{$tenant->id}/create-database")->assertUnprocessable();
    }

    public function test_health_reports_queue_scheduler_and_disk_without_stack_traces(): void
    {
        \DB::table('failed_jobs')->insert([
            'uuid' => (string) \Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}',
            'exception' => "RuntimeException: boom in /var/www/html/app/X.php:12\n#0 /var/www/html/vendor/...", 'failed_at' => now(),
        ]);
        $this->artisan('platform:backups-tick');

        $health = $this->actingAsStaff($this->platformStaff())->getJson('/api/v1/platform/data/health')->assertOk()->json('data');

        $this->assertSame(1, $health['queue']['failed']);
        $this->assertStringNotContainsString('#0', $health['queue']['latest_failures'][0]['message']);
        $this->assertTrue($health['scheduler']['healthy']);
        $this->assertSame('backups-copy', $health['disk']['copy_disk']);
    }

    /**
     * The queue worker (root) creates backup folders and php-fpm (www-data)
     * serves the download. With Flysystem's default 0700 folders every
     * download answered 404 — found by the render check, fixed in
     * config/filesystems.php.
     */
    public function test_backup_folders_are_readable_by_the_web_server(): void
    {
        $root = sys_get_temp_dir().'/bo-perm-'.uniqid();
        $disk = Storage::build(['root' => $root] + config('filesystems.disks.backups'));

        $disk->makeDirectory('tn_alpha');
        $disk->put('tn_alpha/x.dump', 'x');

        $this->assertSame('0755', substr(sprintf('%o', fileperms($root.'/tn_alpha')), -4));
        $this->assertSame('0644', substr(sprintf('%o', fileperms($root.'/tn_alpha/x.dump')), -4));
        $disk->deleteDirectory('tn_alpha');
    }

    public function test_data_read_alone_cannot_back_up_migrate_or_download(): void
    {
        $this->school('alpha');
        $this->actingAsStaff($this->platformStaff(['platform.data.backup']))->postJson('/api/v1/platform/data/backups', ['database' => 'tn_alpha']);
        $backup = Backup::query()->sole();

        $this->actingAsStaff($this->platformStaff(['platform.data.read']));
        $this->getJson('/api/v1/platform/data/databases')->assertOk();
        $this->postJson('/api/v1/platform/data/backups', ['database' => 'tn_alpha'])->assertNotFound();
        $this->get("/api/v1/platform/data/backups/{$backup->id}/download")->assertNotFound();
        $this->postJson('/api/v1/platform/data/migrate', ['tenant_id' => 'all'])->assertNotFound();
    }
}
