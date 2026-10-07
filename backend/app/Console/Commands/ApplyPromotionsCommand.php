<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Performance\PromotionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Applies every approved promotion whose effective date has come, in every
 * active school — the new position / grade / level on the staff record
 * (the new salary was already dated in Payroll). Approving one that's
 * already due applies it straight away; this catches the ones dated ahead.
 */
class ApplyPromotionsCommand extends Command
{
    protected $signature = 'performance:apply-promotions';

    protected $description = 'Apply approved promotions whose effective date has come';

    public function handle(TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $applied = $context->runFor($tenant, fn () => app(PromotionService::class)->applyDue());
            } finally {
                if (! app()->environment('testing')) {
                    $tenantDatabases->purgeTenantConnection();
                }
            }

            $this->components->info("\"{$tenant->name}\": {$applied} promotion(s) applied.");
        }

        return self::SUCCESS;
    }
}
