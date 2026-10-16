<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Platform\Jobs\RunBackup;
use App\Platform\Models\Backup;
use App\Platform\Models\PlatformStaff;
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
    ) {}

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
        $path = sprintf('%s/%s_%s.dump', $backup->database, $backup->database, now()->format('Ymd-His'));
        $started = hrtime(true);
        $backup->forceFill(['started_at' => now()])->save();

        try {
            $disk->makeDirectory($backup->database);
            $this->pgDump->dump($backup->database, $disk->path($path));
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
        $newestGood = Backup::query()->good()
            ->selectRaw('max(id) as id')
            ->groupBy('database')
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
