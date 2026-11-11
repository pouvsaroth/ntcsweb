<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Academic\EnrollmentService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Not started enrollments whose start study date has come turn Studying,
 * in every active school. Runs just after midnight (school time).
 */
class StartDueEnrollmentsCommand extends Command
{
    protected $signature = 'enrollments:start-due';

    protected $description = 'Turn Not started enrollments into Studying on their start study date';

    public function handle(TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $started = $context->runFor($tenant, fn () => app(EnrollmentService::class)->startDue());
            } finally {
                if (! app()->environment('testing')) {
                    $tenantDatabases->purgeTenantConnection();
                }
            }

            $this->components->info("\"{$tenant->name}\": {$started} enrollment(s) started.");
        }

        return self::SUCCESS;
    }
}
