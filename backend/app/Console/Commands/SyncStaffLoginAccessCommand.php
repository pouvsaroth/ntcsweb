<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Staff;
use App\Models\Tenant;
use App\Services\Auth\StaffLoginAccessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Re-applies "only working staff can log in" (StaffLoginAccessService) to
 * every staff member of every active school. Status changes already apply
 * it on save (see Staff::booted()); this is the safety net for rows changed
 * any other way, and brings staff who had already left before the
 * rule existed in line on its first run.
 */
class SyncStaffLoginAccessCommand extends Command
{
    protected $signature = 'staff:sync-login-access';

    protected $description = 'Allow login only for staff whose status is Active, Probation or On leave';

    public function handle(StaffLoginAccessService $access, TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $context->runFor($tenant, function () use ($access) {
                    Staff::query()
                        ->whereNotNull('user_id')
                        ->chunkById(200, function ($staff) use ($access) {
                            foreach ($staff as $member) {
                                $access->sync($member);
                            }
                        });
                });
            } finally {
                if (! app()->environment('testing')) {
                    $tenantDatabases->purgeTenantConnection();
                }
            }

            $this->components->info("\"{$tenant->name}\": staff login access synced.");
        }

        return self::SUCCESS;
    }
}
