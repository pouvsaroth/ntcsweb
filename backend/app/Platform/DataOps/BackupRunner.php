<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Platform\Jobs\RunBackup;
use App\Models\Tenant;
use App\Platform\Models\Backup;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolPlacement;
use Illuminate\Support\Facades\Storage;

/**
 * Backups: queue one, run it (pg_dump to the `backups` disk, then the second
 * copy when one is switched on), and prune by keep-days.
 */
final class BackupRunner
{
    public function __construct(
        private readonly PgDump $pgDump,
        private readonly PlatformDatabases $databases,
        private readonly SchoolExporter $exporter,
    ) {}

    /**
     * Everything worth backing up, once: the central database, the shared
     * database as a whole, every school with a database of its own (dedicated
     * or on a separate server), and each shared school's own rows. Not the
     * template — it holds no data. Used by "Back up all" and the schedule.
     *
     * @return list<Backup>
     */
    public function queueAll(string $trigger, ?PlatformStaff $staff = null): array
    {
        $queued = [];

        foreach ($this->databases->all() as $row) {
            if ($row['kind'] === 'template' || ! $this->databases->exists($row['database'])) {
                continue;
            }

            $queued[] = $this->queue($row['database'], $trigger, $staff);

            foreach ($row['schools'] ?? [] as $school) {
                if (in_array($school->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
                    $queued[] = $this->queueSchool($school, $trigger, $staff);
                }
            }
        }

        return $queued;
    }

    /**
     * One school's own rows (SchoolExporter) — how a school in the shared
     * database is backed up on its own. Kept beside the shared database's
     * backups, marked with the school.
     */
    public function queueSchool(Tenant $tenant, string $trigger, ?PlatformStaff $staff = null): Backup
    {
        $backup = Backup::query()->create([
            'database' => SchoolPlacement::of($tenant)->database,
            'tenant_id' => $tenant->getKey(),
            'trigger' => $trigger,
            'format' => Backup::FORMAT_SCHOOL,
            'staff_id' => $staff?->getKey(),
            'status' => Backup::STATUS_RUNNING,
        ]);

        RunBackup::dispatch($backup->id);

        return $backup;
    }

    /** A `running` row and its queued job. */
    public function queue(string $database, string $trigger, ?PlatformStaff $staff = null): Backup
    {
        $tenant = $this->databases->tenantFor($database);

        $backup = Backup::query()->create([
            'database' => $database,
            'tenant_id' => $tenant?->getKey(),
            'trigger' => $trigger,
            'staff_id' => $staff?->getKey(),
            'status' => Backup::STATUS_RUNNING,
        ]);

        RunBackup::dispatch($backup->id);

        return $backup;
    }

    public function run(Backup $backup): void
    {
        $disk = Storage::disk(config('platform.backup_disk'));
        $school = $backup->format === Backup::FORMAT_SCHOOL ? $backup->tenant : null;
        $path = $school
            ? sprintf('%s/schools/%s_%s.zip', $backup->database, $school->slug, now()->format('Ymd-His'))
            : sprintf('%s/%s_%s.dump', $backup->database, $backup->database, now()->format('Ymd-His'));
        $started = hrtime(true);
        $backup->forceFill(['started_at' => now()])->save();

        try {
            $disk->makeDirectory(dirname($path));
            if ($school !== null) {
                $this->exporter->export($school, $disk->path($path));
            } else {
                $this->pgDump->dump($backup->database, $disk->path($path), $this->serverOf($backup->database));
            }
        } catch (\Throwable $e) {
            if ($disk->exists($path)) {
                $disk->delete($path);
            }
            $backup->forceFill([
                'status' => Backup::STATUS_FAILED,
                'error' => $e->getMessage(),
                'duration_ms' => $this->ms($started),
                'finished_at' => now(),
            ])->save();

            return;
        }

        $backup->forceFill([
            'status' => Backup::STATUS_OK,
            'path' => $path,
            'size_bytes' => $disk->size($path),
            'duration_ms' => $this->ms($started),
            'finished_at' => now(),
        ])->save();

        $this->copy($backup);
    }

    /**
     * A database on a separate server is dumped with that school's own login
     * and server; everything else with this server's.
     *
     * @return array{host: string, port: int, username: string, password: string, sslmode: ?string}|null
     */
    private function serverOf(string $database): ?array
    {
        $tenant = $this->databases->tenantFor($database);
        $placement = $tenant ? SchoolPlacement::of($tenant) : null;

        if (! $placement?->isSeparate()) {
            return null;
        }

        return [
            'host' => $placement->host,
            'port' => $placement->port,
            'username' => $placement->username,
            'password' => $placement->password,
            'sslmode' => $placement->sslmode,
            'tenant_id' => $tenant->id,
        ];
    }

    /** The second copy. Its failure never fails the backup — it is recorded beside it. */
    public function copy(Backup $backup): void
    {
        $copyDisk = config('platform.backup_copy_disk');
        if (! $copyDisk || $backup->path === null) {
            return;
        }

        try {
            $stream = Storage::disk(config('platform.backup_disk'))->readStream($backup->path);
            Storage::disk($copyDisk)->writeStream($backup->path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            $backup->forceFill(['copied_at' => now(), 'copy_error' => null])->save();
        } catch (\Throwable $e) {
            $backup->forceFill(['copy_error' => $e->getMessage()])->save();
        }
    }

    /**
     * Remove backups older than keep-days — files on both disks and the row —
     * but never a database's newest good backup. Failed rows go too.
     */
    public function prune(int $keepDays): int
    {
        $cutoff = now()->subDays($keepDays);
        // Newest good one of each kind: per database, per school, per format —
        // a shared school's exports sit beside the shared database's dumps
        // and must not crowd each other out.
        $newestGood = Backup::query()->good()
            ->selectRaw('max(id) as id')
            ->groupBy('database', 'tenant_id', 'format')
            ->pluck('id')
            ->all();

        $old = Backup::query()
            ->where('status', '!=', Backup::STATUS_RUNNING)
            ->where('created_at', '<', $cutoff)
            ->whereNotIn('id', $newestGood)
            ->get();

        foreach ($old as $backup) {
            $this->deleteFiles($backup);
            $backup->delete();
        }

        return $old->count();
    }

    public function deleteFiles(Backup $backup): void
    {
        if ($backup->path === null) {
            return;
        }

        foreach (array_filter([config('platform.backup_disk'), config('platform.backup_copy_disk')]) as $disk) {
            try {
                Storage::disk($disk)->delete($backup->path);
            } catch (\Throwable) {
                // A copy already gone is not a reason to keep the row.
            }
        }
    }

    private function ms(int $started): int
    {
        return (int) ((hrtime(true) - $started) / 1_000_000);
    }
}
