<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\BackupRunner;
use App\Platform\Models\Backup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One backup, in the background. One try — a failure is recorded on the row for a person to see. */
class RunBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $backupId) {}

    public function handle(BackupRunner $runner): void
    {
        $backup = Backup::query()->find($this->backupId);

        if ($backup !== null && $backup->status === Backup::STATUS_RUNNING && $backup->started_at === null) {
            $runner->run($backup);
        }
    }
}
