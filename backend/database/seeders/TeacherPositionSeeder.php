<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Stancl\Tenancy\Database\DatabaseManager;

/**
 * Idempotent, like RolePermissionSeeder — safe to run on every deploy. A
 * class's teacher is a Staff member whose Position is named "Teacher" (see
 * StoreSchoolClassRequest); unlike every other Position, this one needs a
 * guaranteed row to filter/validate against rather than being purely
 * admin-created free text. Must run after RolePermissionSeeder, which is
 * what guarantees every tenant's Role::TEACHER row already exists.
 */
class TeacherPositionSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->withoutGlobalScopes()->chunkById(50, function ($tenants) {
            foreach ($tenants as $tenant) {
                $this->ensureTeacherPosition($tenant);
            }
        });
    }

    private function ensureTeacherPosition(Tenant $tenant): void
    {
        // `positions` lives in the tenant database — runFor() only sets the
        // logical TenantContext, it never points the `tenant` connection at
        // this tenant's actual database. A seeder looping over every tenant
        // is exactly the long-lived, multi-tenant process ResolveTenant's
        // own docblock warns about, so that has to happen explicitly here —
        // the same call ResolveTenant/ProcessStudentImport make. Skipped
        // under the test runner for the same reason those skip it.
        $tenantDatabases = app(DatabaseManager::class);
        if (! app()->environment('testing')) {
            $tenantDatabases->createTenantConnection($tenant);
        }

        try {
            app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
                $teacherRole = Role::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->getKey())
                    ->where('slug', Role::TEACHER)
                    ->first();

                if ($teacherRole === null) {
                    return;
                }

                Position::query()->firstOrCreate(
                    ['name' => 'Teacher'],
                    ['role_id' => $teacherRole->getKey(), 'description' => 'Classroom teaching staff.'],
                );
            });
        } finally {
            if (! app()->environment('testing')) {
                $tenantDatabases->purgeTenantConnection();
            }
        }
    }
}
