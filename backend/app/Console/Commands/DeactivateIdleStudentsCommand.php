<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Student;
use App\Models\Tenant;
use App\Services\Academic\StudentAccessService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Daily: for every active school, re-derives each student's "stopped
 * studying" countdown from their enrollments (which also picks up students
 * who stopped before this feature existed), then switches off every account
 * whose grace period is over — see StudentAccessService.
 */
class DeactivateIdleStudentsCommand extends Command
{
    protected $signature = 'students:deactivate-idle';

    protected $description = 'Set student accounts inactive once they have stopped studying for longer than the school\'s grace period';

    public function handle(StudentAccessService $access, TenantContext $context, DatabaseManager $tenantDatabases): int
    {
        foreach (Tenant::active()->get() as $tenant) {
            if (! app()->environment('testing')) {
                $tenantDatabases->createTenantConnection($tenant);
            }

            try {
                $count = $context->runFor($tenant, function () use ($access, $tenant) {
                    Student::query()
                        ->whereNotNull('user_id')
                        ->with('user')
                        ->chunkById(200, function ($students) use ($access) {
                            foreach ($students as $student) {
                                $access->sync($student);
                            }
                        });

                    return $access->expireDueForTenant($tenant);
                });
            } finally {
                if (! app()->environment('testing')) {
                    $tenantDatabases->purgeTenantConnection();
                }
            }

            $this->components->info("\"{$tenant->name}\": {$count} account(s) set inactive.");
        }

        return self::SUCCESS;
    }
}
