<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\DataOps\BackupRunner;
use App\Platform\DataOps\PlatformDatabases;
use App\Platform\Models\BackupSchedule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every minute, from the scheduler (routes/console.php):
 *
 *   - records that the scheduler is alive (bo_heartbeats) — the Health panel
 *     shows a stale tick as "scheduler down", which also means no backups;
 *   - when the schedule is due, queues one backup per existing database and
 *     marks the slot taken, so a slot never runs twice;
 *   - prunes backups past keep-days (never a database's newest good one).
 */
class BackupsTickCommand extends Command
{
    protected $signature = 'platform:backups-tick';

    protected $description = 'Scheduler tick for back-office backups: heartbeat, scheduled run, retention';

    public function handle(BackupRunner $runner, PlatformDatabases $databases): int
    {
        DB::connection(config('tenancy.database.central_connection'))->table('bo_heartbeats')
            ->upsert([['name' => 'scheduler', 'beat_at' => now()]], ['name'], ['beat_at']);

        $schedule = BackupSchedule::current();

        if ($schedule->isDue(now())) {
            // Take the slot first: a second tick overlapping this one must not start another run.
            $schedule->forceFill(['last_run_at' => now()])->save();

            $queued = count($runner->queueAll('scheduled'));
            $this->components->info("Queued {$queued} scheduled backup(s).");
        }

        $pruned = $runner->prune($schedule->keep_days);
        if ($pruned > 0) {
            $this->components->info("Removed {$pruned} old backup(s).");
        }

        return self::SUCCESS;
    }
}
