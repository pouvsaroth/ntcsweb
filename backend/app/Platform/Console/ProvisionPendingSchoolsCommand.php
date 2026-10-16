<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Models\Tenant;
use App\Platform\Jobs\ProvisionSchool;
use Illuminate\Console\Command;

/**
 * Re-queues every school stuck in `provisioning` — say the queue worker was
 * restarted mid-job. Safe to run at any time: every provisioning step is
 * idempotent, and ProvisionSchool is unique per school.
 */
class ProvisionPendingSchoolsCommand extends Command
{
    protected $signature = 'platform:provision-pending';

    protected $description = 'Re-queue the setup of every school still provisioning';

    public function handle(): int
    {
        $ids = Tenant::query()->where('status', Tenant::STATUS_PROVISIONING)->pluck('id');

        foreach ($ids as $id) {
            ProvisionSchool::dispatch((int) $id);
        }

        $this->components->info("Queued {$ids->count()} school(s).");

        return self::SUCCESS;
    }
}
