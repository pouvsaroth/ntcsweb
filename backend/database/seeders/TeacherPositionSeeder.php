<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

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
    }
}
