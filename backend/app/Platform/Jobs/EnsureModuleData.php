<?php

declare(strict_types=1);

namespace App\Platform\Jobs;

use App\Models\Tenant;
use App\Modules\ModuleSetup;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A customer got a new subscription (another system, another plan): the
 * starting data of its modules, in the background (docs/architecture/multi-product.md).
 * A customer still being set up is skipped — its setup seeds them.
 */
class EnsureModuleData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public readonly int $tenantId) {}

    public function uniqueId(): string
    {
        return (string) $this->tenantId;
    }

    public function handle(ModuleSetup $setup): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null || ! in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
            return;
        }

        $setup->seedFor($tenant);
    }
}
