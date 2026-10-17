<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Jobs\MoveSchool;
use App\Platform\Models\Backup;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolMove;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolStatus;
use App\Platform\Support\PlatformAudit;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\Tenancy\SchoolConnection;
use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Moves a school's data between placements (docs/backoffice/stage-4-database-placement.md, 4d):
 * out of the shared database, or between its own database and a separate
 * server — never INTO the shared database (its ids would collide there).
 *
 *   check     both ends reachable and fully migrated, the target empty for this school
 *   suspend   the school's users are signed out; nothing writes while it is copied
 *   backup    its rows exported (kept as an ordinary backup)
 *   prepare   the target made: dedicated → cloned from the template; separate → migrated
 *   copy      the export written into the target, parents first
 *   verify    row counts compared table by table
 *   switch    the school's placement now points at the target
 *   reactivate
 *
 * A failure before "switch" leaves the school where it was, reactivated, and
 * removes what was made at the target. The old copy is kept until staff
 * remove it (removeOldCopy).
 */
class SchoolMover
{
    public const MOVES = [
        SchoolPlacement::SHARED => [SchoolPlacement::DEDICATED, SchoolPlacement::SEPARATE],
        SchoolPlacement::DEDICATED => [SchoolPlacement::SEPARATE],
        SchoolPlacement::SEPARATE => [SchoolPlacement::DEDICATED],
    ];

    private const TARGET = 'move_target';

    public function __construct(
        private readonly SchoolExporter $exporter,
        private readonly SchoolImporter $importer,
        private readonly TenantProvisioningService $provisioning,
        private readonly SchoolStatus $status,
        private readonly PlatformAudit $audit,
    ) {}

    /** @param array<string, mixed> $to the target placement (mode, and for separate the server) */
    public function start(Tenant $tenant, array $to, ?PlatformStaff $staff = null): SchoolMove
    {
        $from = SchoolPlacement::of($tenant);

        if (! in_array($to['mode'], self::MOVES[$from->mode] ?? [], true)) {
            throw ValidationException::withMessages(['placement' => __('A school that is :from cannot be moved to :to.', ['from' => $from->mode, 'to' => $to['mode']])]);
        }
        if (! in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            throw ValidationException::withMessages(['placement' => __('Only an active or suspended school can be moved.')]);
        }
        if (SchoolMove::query()->where('tenant_id', $tenant->id)->whereIn('status', [SchoolMove::QUEUED, SchoolMove::RUNNING])->exists()) {
            throw ValidationException::withMessages(['placement' => __('This school is already being moved.')]);
        }

        $target = $to['mode'] === SchoolPlacement::DEDICATED
            ? ['mode' => SchoolPlacement::DEDICATED, 'database' => config('tenancy.database.name_prefix').$tenant->slug]
            : ['mode' => SchoolPlacement::SEPARATE] + array_intersect_key($to, array_flip(['database', 'host', 'port', 'username', 'password', 'sslmode']));

        $move = SchoolMove::query()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff?->id,
            'from_mode' => $from->mode,
            'to_mode' => $target['mode'],
            'from_placement' => $from->only(['mode', 'database', 'host', 'port', 'username', 'password', 'sslmode']),
            'to_placement' => $target,
            'status' => SchoolMove::QUEUED,
            'steps' => [],
        ]);

        $this->audit->record('school.move_started', $tenant, ['placement' => $from->mode], ['placement' => $target['mode']], tenantId: $tenant->id, actor: $staff);
        MoveSchool::dispatch($move->id);

        return $move;
    }

    public function run(SchoolMove $move): void
    {
        $tenant = $move->tenant;
        $move->forceFill(['status' => SchoolMove::RUNNING, 'previous_status' => $tenant->status])->save();
        $switched = false;
        $targetMade = false;

        try {
            $this->step($move, 'check', fn () => $this->check($move));

            $this->step($move, 'suspend', function () use ($move, $tenant) {
                if ($tenant->fresh()->status === Tenant::STATUS_ACTIVE) {
                    $this->status->move($tenant->fresh(), Tenant::STATUS_SUSPENDED, __('Moving the school\'s data'), $move->staff);
                }
            });

            $this->step($move, 'backup', function () use ($move, $tenant) {
                $backup = Backup::query()->create([
                    'database' => $move->placement('from')->database, 'tenant_id' => $tenant->id, 'trigger' => 'move',
                    'format' => Backup::FORMAT_SCHOOL, 'staff_id' => $move->staff_id, 'status' => Backup::STATUS_RUNNING,
                ]);
                app(BackupRunner::class)->run($backup);
                $backup->refresh();
                if ($backup->status !== Backup::STATUS_OK) {
                    throw new RuntimeException('Backup failed: '.$backup->error);
                }
                $move->forceFill(['backup_id' => $backup->id])->save();
            });

            $this->step($move, 'prepare', function () use ($move, &$targetMade) {
                $this->prepareTarget($move);
                $targetMade = true;
            });

            $this->step($move, 'copy', function () use ($move) {
                $path = Storage::disk(config('platform.backup_disk'))->path($move->fresh()->backup->path);
                $written = $this->withTarget($move, fn (Connection $db) => $db->transaction(fn () => $this->importer->import($path, $db)));
                $move->forceFill(['counts' => ['written' => $written]])->save();
            });

            $this->step($move, 'verify', function () use ($move) {
                $manifest = $this->manifest($move);
                $found = $this->withTarget($move, fn (Connection $db) => collect($manifest['tables'])->mapWithKeys(
                    fn ($n, $table) => [$table => $db->table($table)->where(SchoolTables::COLUMN, $move->tenant_id)->count()]
                )->all());
                $wrong = array_filter($manifest['tables'], fn ($n, $table) => ($found[$table] ?? -1) !== $n, ARRAY_FILTER_USE_BOTH);
                $move->forceFill(['counts' => ['source' => $manifest['tables'], 'copy' => $found]])->save();
                if ($wrong !== []) {
                    throw new RuntimeException('Row counts differ in: '.implode(', ', array_keys($wrong)));
                }
            });

            $this->step($move, 'switch', function () use ($move, $tenant, &$switched) {
                $to = $move->placement('to');
                SchoolPlacement::query()->updateOrCreate(['tenant_id' => $tenant->id], $to->only(['mode', 'database', 'host', 'port', 'username', 'password', 'sslmode']));
                $switched = true;
            });

            $this->step($move, 'reactivate', fn () => $this->restoreStatus($move, __('Move finished')));

            $move->forceFill(['status' => SchoolMove::DONE])->save();
            $this->audit->record('school.moved', $tenant, ['placement' => $move->from_mode], ['placement' => $move->to_mode], tenantId: $tenant->id, actor: $move->staff);
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            if (! $switched) {
                // Back where it was: remove what was made at the target, reopen the school.
                if ($targetMade) {
                    try {
                        $this->discardTarget($move);
                    } catch (\Throwable $cleanup) {
                        $error .= ' — and cleaning up the target failed: '.$cleanup->getMessage();
                    }
                }
                $this->restoreStatus($move, __('Move failed — the school stays where it was'));
            }

            // Only now: whoever is watching sees "failed" with the school already back to normal.
            $move->forceFill(['status' => SchoolMove::FAILED, 'error' => $error])->save();

            $this->audit->record('school.move_failed', $tenant, new: ['error' => $e->getMessage()], tenantId: $tenant->id, actor: $move->staff);
        }
    }

    /** After a move: delete the school's data at its old place. Staff decide when. */
    public function removeOldCopy(SchoolMove $move, ?PlatformStaff $staff = null): void
    {
        if ($move->status !== SchoolMove::DONE || $move->old_copy_removed_at !== null) {
            throw ValidationException::withMessages(['move' => __('Only a finished move has an old copy to remove, once.')]);
        }

        $old = $move->placement('from');
        $current = SchoolPlacement::of($move->tenant);
        if ($old->database === $current->database && $old->host === $current->host) {
            throw ValidationException::withMessages(['move' => __('The old copy is where the school lives now.')]);
        }

        if ($old->mode === SchoolPlacement::DEDICATED) {
            $this->dropDatabase($old->database);
        } else {
            $this->withConnection($old, $move->tenant_id, fn (Connection $db) => $this->importer->deleteAll($db, $move->tenant_id));
        }

        $move->forceFill(['old_copy_removed_at' => now()])->save();
        $this->audit->record('school.move_old_copy_removed', $move->tenant, new: ['placement' => $old->mode, 'database' => $old->database], tenantId: $move->tenant_id, actor: $staff);
    }

    private function check(SchoolMove $move): void
    {
        // The source fully migrated (a move copies columns as they are now).
        $this->provisioning->provision($move->tenant);

        $to = $move->placement('to');
        if ($to->mode === SchoolPlacement::DEDICATED && $this->provisioning->exists($to->database)) {
            throw new RuntimeException("A database named {$to->database} already exists on this server.");
        }
        if ($to->mode === SchoolPlacement::SEPARATE) {
            $probe = app(\App\Platform\Services\SeparateServerProbe::class)->probe($to->only(['host', 'port', 'database', 'username', 'password', 'sslmode']));
            if (! $probe['ok']) {
                throw new RuntimeException($probe['message']);
            }
        }
    }

    private function prepareTarget(SchoolMove $move): void
    {
        $to = $move->placement('to');

        if ($to->mode === SchoolPlacement::DEDICATED) {
            $this->provisioning->prepareSchoollessDatabase($this->provisioning->templateName());
            $this->provisioning->cloneTemplateInto($to->database);
        } else {
            $this->provisioning->prepareSeparateDatabase($this->connectionConfig($to));
        }

        // Up to date and empty for this school, or nothing is copied.
        $source = $this->manifest($move)['migrations'];
        $this->withTarget($move, function (Connection $db) use ($source, $move) {
            $missing = array_diff($source, $db->table('migrations')->pluck('migration')->all());
            if ($missing !== []) {
                throw new RuntimeException('The target is missing migrations: '.implode(', ', array_slice($missing, 0, 3)));
            }
            foreach (SchoolTables::all($db) as $table) {
                if ($db->table($table)->where(SchoolTables::COLUMN, $move->tenant_id)->exists()) {
                    throw new RuntimeException("The target already holds this school's rows ({$table}).");
                }
            }
        });
    }

    private function discardTarget(SchoolMove $move): void
    {
        $to = $move->placement('to');

        if ($to->mode === SchoolPlacement::DEDICATED) {
            $this->dropDatabase($to->database);
        } else {
            $this->withTarget($move, fn (Connection $db) => $this->importer->deleteAll($db, $move->tenant_id));
        }
    }

    private function restoreStatus(SchoolMove $move, string $reason): void
    {
        $tenant = $move->tenant->fresh();
        if ($move->previous_status === Tenant::STATUS_ACTIVE && $tenant->status === Tenant::STATUS_SUSPENDED) {
            $this->status->move($tenant, Tenant::STATUS_ACTIVE, $reason, $move->staff);
        }
    }

    /** @return array{tables: array<string, int>, migrations: list<string>} */
    private function manifest(SchoolMove $move): array
    {
        $zip = new \ZipArchive;
        $zip->open(Storage::disk(config('platform.backup_disk'))->path($move->fresh()->backup->path));

        try {
            return json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        } finally {
            $zip->close();
        }
    }

    private function withTarget(SchoolMove $move, callable $callback): mixed
    {
        return $this->withConnection($move->placement('to'), $move->tenant_id, $callback);
    }

    /**
     * A connection to a placement, as its owner on this server (no SET ROLE,
     * explicit tenant_id filters) or with the separate server's own login
     * (bound by RLS, so the school is set on the session).
     */
    private function withConnection(SchoolPlacement $placement, int $tenantId, callable $callback): mixed
    {
        config(['database.connections.'.self::TARGET => $this->connectionConfig($placement)]);

        try {
            $db = DB::connection(self::TARGET);
            $db->select("select set_config('app.tenant_id', ?, false)", [(string) $tenantId]);

            return $callback($db);
        } finally {
            DB::purge(self::TARGET);
        }
    }

    /** @return array<string, mixed> */
    private function connectionConfig(SchoolPlacement $placement): array
    {
        $base = config('database.connections.'.config('tenancy.database.central_connection'));

        return $placement->mode === SchoolPlacement::SEPARATE
            ? array_merge($base, [
                'url' => null, 'host' => $placement->host, 'port' => $placement->port, 'database' => $placement->database,
                'username' => $placement->username, 'password' => $placement->password, 'sslmode' => $placement->sslmode ?: 'prefer',
                'search_path' => 'public', 'app_role' => '',
            ])
            : array_merge($base, ['database' => $placement->database, 'search_path' => 'public']);
    }

    private function dropDatabase(string $database): void
    {
        $central = DB::connection(config('tenancy.database.central_connection'));
        $central->select('select pg_terminate_backend(pid) from pg_stat_activity where datname = ? and pid <> pg_backend_pid()', [$database]);
        $central->statement('DROP DATABASE IF EXISTS "'.str_replace('"', '""', $database).'"');
    }

    private function step(SchoolMove $move, string $name, callable $work): void
    {
        $started = hrtime(true);

        try {
            $work();
        } catch (\Throwable $e) {
            $this->log($move, $name, 'failed', $e->getMessage(), $started);

            throw $e;
        }

        $this->log($move, $name, 'ok', null, $started);
    }

    private function log(SchoolMove $move, string $step, string $status, ?string $message, int $started): void
    {
        $steps = $move->fresh()->steps ?? [];
        $steps[] = ['step' => $step, 'status' => $status, 'message' => $message, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        $move->forceFill(['steps' => $steps])->save();
    }
}
