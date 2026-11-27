<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Academic\TeacherAbsenceAlertService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Every school: tell whoever arranges substitutes when a teacher with a class
 * today is on leave or has not checked in — see TeacherAbsenceAlertService.
 */
class AlertAbsentTeachersCommand extends Command
{
    protected $signature = 'teachers:alert-absent';

    protected $description = 'Notify substitute managers when a teacher with a class today is on leave or has not checked in';

    public function handle(TeacherAbsenceAlertService $alerts, TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $count = $context->runFor($tenant, fn () => $alerts->alertForTenant($tenant));
            } catch (\Throwable $e) {
                // One school's failure never stops the others.
                report($e);
                $count = 0;
            } finally {
                if (! app()->environment('testing')) {
                    $tenantDatabases->purgeTenantConnection();
                }
            }

            if ($count > 0) {
                $this->components->info("\"{$tenant->name}\": {$count} teacher absence alert(s).");
            }
        }

        return self::SUCCESS;
    }
}
