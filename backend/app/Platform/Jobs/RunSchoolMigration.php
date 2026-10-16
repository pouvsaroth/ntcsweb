<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Platform\DataOps\SchoolMigrator;
use App\Platform\Models\MigrationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** One school's migrate / create-database, in the background. Its result is on the run row. */
class RunSchoolMigration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(SchoolMigrator $migrator): void
    {
        $run = MigrationRun::query()->find($this->runId);

        if ($run !== null && $run->status === 'queued') {
            $migrator->run($run);
        }
    }
}
