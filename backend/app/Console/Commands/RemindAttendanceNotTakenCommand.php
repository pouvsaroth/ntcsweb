<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Academic\AttendanceReminderService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Every 15 minutes: for every active school, tell class teachers and admins
 * about each class that ended without attendance — see AttendanceReminderService.
 */
class RemindAttendanceNotTakenCommand extends Command
{
    protected $signature = 'attendance:remind-not-taken';

    protected $description = 'Notify class teachers and school admins when a class ended without attendance being added';

    public function handle(AttendanceReminderService $reminders, TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $count = $context->runFor($tenant, fn () => $reminders->remindForTenant($tenant));
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
                $this->components->info("\"{$tenant->name}\": {$count} class(es) reminded.");
            }
        }

        return self::SUCCESS;
    }
}
