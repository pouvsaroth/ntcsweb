<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Jobs\SwapSchoolDatabase;
use App\Platform\Models\Backup;
use App\Platform\Models\DatabaseSwap;
use App\Platform\Models\ExtraDatabase;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolMove;
use App\Platform\Models\SchoolPlacement;
use App\Platform\Services\SchoolStatus;
use App\Platform\Support\PlatformAudit;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Data ops > Databases > Use for a school: puts a ready "Other" database
 * (usually a restored backup) in place of a school's own database on this
 * server. In the background, step by step:
 *
 *   check       both databases there, the restored one holds this school's rows,
 *               the "kept as" name free
 *   suspend     the school's users are signed out; nothing writes meanwhile
 *   backup      a fresh whole-database backup of the live one
 *   swap        live → <live>_before_restore, restored → <live>
 *   migrate     the restored copy brought up to today's migrations
 *   reactivate
 *
 * A failure before the school is migrated puts the names back and reopens
 * the school. Putting the kept copy back in (an undo) trades the two places. The old live database is kept, listed as an "Other" database,
 * until staff delete it.
 */
class DatabaseSwapper
{
    public const KEPT_SUFFIX = '_before_restore';

    public function __construct(
        private readonly PgDatabases $server,
        private readonly BackupRunner $backups,
        private readonly SchoolStatus $status,
        private readonly PlatformAudit $audit,
    ) {}

    public function start(ExtraDatabase $database, Tenant $tenant, ?PlatformStaff $staff = null): DatabaseSwap
    {
        $fail = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message]);

        if ($database->status !== ExtraDatabase::STATUS_READY || ! $this->server->exists($database->name)) {
            $fail('database', __('Only a ready database can be used for a school.'));
        }

        $placement = SchoolPlacement::of($tenant);
        if ($placement->mode !== SchoolPlacement::DEDICATED) {
            $fail('tenant_id', __('Only a school with its own database on this server can take a restored database.'));
        }
        if (! in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            $fail('tenant_id', __('Only an active or suspended school can take a restored database.'));
        }
        $busy = [DatabaseSwap::QUEUED, DatabaseSwap::RUNNING];
        if (DatabaseSwap::query()->whereIn('status', $busy)->where(fn ($q) => $q->where('tenant_id', $tenant->id)->orWhere('restored_database', $database->name))->exists()
            || SchoolMove::query()->where('tenant_id', $tenant->id)->whereIn('status', [SchoolMove::QUEUED, SchoolMove::RUNNING])->exists()) {
            $fail('tenant_id', __('This school or database is already being changed.'));
        }

        $live = (string) $placement->database;
        if (! $this->server->exists($live)) {
            $fail('tenant_id', __('The school\'s database :name is not on this server.', ['name' => $live]));
        }
        $keptAs = self::keptName($live);
        // Putting the kept copy back (an undo) just trades the two places.
        if ($keptAs !== $database->name && ($this->server->exists($keptAs) || ExtraDatabase::query()->where('name', $keptAs)->exists())) {
            $fail('tenant_id', __('Delete :name first (the copy kept from the last restore).', ['name' => $keptAs]));
        }

        $swap = DatabaseSwap::query()->create([
            'tenant_id' => $tenant->id,
            'staff_id' => $staff?->id,
            'live_database' => $live,
            'restored_database' => $database->name,
            'kept_as' => $keptAs,
            'status' => DatabaseSwap::QUEUED,
            'steps' => [],
        ]);

        $this->audit->record('database.swap_started', $tenant, new: ['database' => $live, 'restored' => $database->name], tenantId: $tenant->id, actor: $staff);
        SwapSchoolDatabase::dispatch($swap->id);

        return $swap;
    }

    public static function keptName(string $live): string
    {
        return mb_substr($live, 0, 63 - strlen(self::KEPT_SUFFIX)).self::KEPT_SUFFIX;
    }

    public function run(DatabaseSwap $swap): void
    {
        $tenant = $swap->tenant;
        $swap->forceFill(['status' => DatabaseSwap::RUNNING, 'previous_status' => $tenant->status])->save();
        /** @var list<array{0: string, 1: string}> $renamed done so far, undone in reverse on a failure */
        $renamed = [];
        $committed = false;
        $undo = $swap->restored_database === $swap->kept_as;

        try {
            $this->step($swap, 'check', function () use ($swap, $tenant, $undo) {
                foreach ([$swap->live_database, $swap->restored_database] as $name) {
                    if (! $this->server->exists($name)) {
                        throw new RuntimeException("{$name} is not on this server.");
                    }
                }
                if (! $undo && $this->server->exists($swap->kept_as)) {
                    throw new RuntimeException("{$swap->kept_as} already exists; delete it first.");
                }
                if ($this->server->schoolRows($swap->restored_database, $tenant->id) === 0) {
                    throw new RuntimeException("{$swap->restored_database} holds no data of {$tenant->name} (no staff, students or academic years) — was it restored from this school's backup?");
                }
            });

            $this->step($swap, 'suspend', function () use ($swap, $tenant) {
                if ($tenant->fresh()->status === Tenant::STATUS_ACTIVE) {
                    $this->status->move($tenant->fresh(), Tenant::STATUS_SUSPENDED, __('Putting a restored database in place'), $swap->staff);
                }
            });

            $this->step($swap, 'backup', function () use ($swap, $tenant) {
                $backup = Backup::query()->create([
                    'database' => $swap->live_database, 'tenant_id' => $tenant->id, 'trigger' => 'restore',
                    'format' => Backup::FORMAT_DUMP, 'staff_id' => $swap->staff_id, 'status' => Backup::STATUS_RUNNING,
                ]);
                $this->backups->run($backup);
                $backup->refresh();
                $swap->forceFill(['backup_id' => $backup->id])->save();
                if ($backup->status !== Backup::STATUS_OK) {
                    throw new RuntimeException('Backup failed: '.$backup->error);
                }
            });

            $this->step($swap, 'swap', function () use ($swap, &$renamed, $undo) {
                $moves = $undo
                    ? [[$swap->live_database, $parked = self::parkedName($swap->live_database)], [$swap->restored_database, $swap->live_database], [$parked, $swap->kept_as]]
                    : [[$swap->live_database, $swap->kept_as], [$swap->restored_database, $swap->live_database]];
                foreach ($moves as [$from, $to]) {
                    $this->server->rename($from, $to);
                    $renamed[] = [$from, $to];
                }
            });

            $this->step($swap, 'migrate', fn () => $this->server->migrateSchool($tenant->fresh()));
            $committed = true;

            $this->step($swap, 'reactivate', fn () => $this->restoreStatus($swap, __('Restored database in place')));

            // The restored database is the school's now; the old live one is kept as "Other".
            ExtraDatabase::query()->where('name', $swap->restored_database)->delete();
            ExtraDatabase::query()->create([
                'name' => $swap->kept_as,
                'source' => ExtraDatabase::SOURCE_REPLACED,
                'source_database' => $swap->live_database,
                'status' => ExtraDatabase::STATUS_READY,
                'note' => __(':school\'s database before :restored was put in its place.', ['school' => $tenant->name, 'restored' => $swap->restored_database]),
                'staff_id' => $swap->staff_id,
                'finished_at' => now(),
            ]);

            $swap->forceFill(['status' => DatabaseSwap::DONE, 'finished_at' => now()])->save();
            $this->audit->record('database.swapped', $tenant, ['database' => $swap->live_database], ['restored' => $swap->restored_database, 'kept_as' => $swap->kept_as], tenantId: $tenant->id, actor: $swap->staff);
        } catch (\Throwable $e) {
            $error = $e->getMessage();

            if (! $committed) {
                // Both names back, then the school reopened — unless putting them back failed.
                try {
                    foreach (array_reverse($renamed) as [$from, $to]) {
                        $this->server->rename($to, $from);
                    }
                    $this->restoreStatus($swap, __('Restore not put in place — the school keeps its database'));
                } catch (\Throwable $undo) {
                    $error .= ' — and putting the names back failed, so the school stays suspended: '.$undo->getMessage();
                }
            }

            $swap->forceFill(['status' => DatabaseSwap::FAILED, 'error' => mb_substr($error, 0, 4000), 'finished_at' => now()])->save();
            $this->audit->record('database.swap_failed', $tenant, new: ['error' => $e->getMessage()], tenantId: $tenant->id, actor: $swap->staff);
        }
    }

    /** A short-lived name while two databases trade places. */
    private static function parkedName(string $live): string
    {
        return mb_substr($live, 0, 63 - 10).'_swap_park';
    }

    private function restoreStatus(DatabaseSwap $swap, string $reason): void
    {
        $tenant = $swap->tenant->fresh();
        if ($swap->previous_status === Tenant::STATUS_ACTIVE && $tenant->status === Tenant::STATUS_SUSPENDED) {
            $this->status->move($tenant, Tenant::STATUS_ACTIVE, $reason, $swap->staff);
        }
    }

    private function step(DatabaseSwap $swap, string $name, callable $work): void
    {
        $started = hrtime(true);

        try {
            $work();
        } catch (\Throwable $e) {
            $this->log($swap, $name, 'failed', $e->getMessage(), $started);

            throw $e;
        }

        $this->log($swap, $name, 'ok', null, $started);
    }

    private function log(DatabaseSwap $swap, string $step, string $status, ?string $message, int $started): void
    {
        $steps = $swap->fresh()->steps ?? [];
        $steps[] = ['step' => $step, 'status' => $status, 'message' => $message !== null ? mb_substr($message, 0, 500) : null, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
        $swap->forceFill(['steps' => $steps])->save();
    }
}
