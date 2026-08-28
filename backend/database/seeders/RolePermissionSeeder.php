<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to run on every deploy, not just on first install.
 *
 * 1. Syncs the permissions table from the {@see Permissions} catalog.
 * 2. Ensures the platform "super-admin" role exists.
 * 3. Ensures every tenant has its four system roles (school-admin, teacher,
 *    staff, student), each carrying the catalog's default permission set.
 * 4. Bootstraps existing data: any tenant user holding no role yet is made
 *    that school's admin, so the pre-existing NewTech account keeps working
 *    once RBAC checks are enforced.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistry::class)->sync();

        $this->ensurePlatformSuperAdminRole();

        $permissionIds = Permission::query()->pluck('id', 'slug');

        Tenant::query()->withoutGlobalScopes()->chunkById(50, function ($tenants) use ($permissionIds) {
            foreach ($tenants as $tenant) {
                $this->ensureTenantRoles($tenant, $permissionIds);
            }
        });

        $this->bootstrapUnassignedUsers();
    }

    private function ensurePlatformSuperAdminRole(): void
    {
        $this->putRole(
            tenantId: null,
            slug: Role::SUPER_ADMIN,
            name: 'Super Admin',
            level: Role::LEVELS[Role::SUPER_ADMIN],
            description: 'Full access across every school on the platform.',
        );
    }

    /**
     * @param  \Illuminate\Support\Collection<string, int>  $permissionIds
     */
    private function ensureTenantRoles(Tenant $tenant, $permissionIds): void
    {
        $names = [
            Role::SCHOOL_ADMIN => 'School Admin',
            Role::TEACHER => 'Teacher',
            Role::STAFF => 'Staff',
            Role::STUDENT => 'Student',
        ];

        foreach (Permissions::defaultsForSystemRoles() as $slug => $defaultSlugs) {
            $role = $this->putRole(
                tenantId: $tenant->getKey(),
                slug: $slug,
                name: $names[$slug],
                level: Role::LEVELS[$slug],
            );

            $ids = collect($defaultSlugs)->map(fn ($slug) => $permissionIds[$slug] ?? null)->filter()->values();

            $role->permissions()->sync($ids);
        }
    }

    /**
     * updateOrCreate() fills through the model's normal guarded $fillable,
     * which deliberately excludes tenant_id and is_system (see Role's
     * docblock) so that request input can never set them. Seeded roles are
     * trusted, so this sets every column explicitly via forceFill instead.
     */
    private function putRole(?int $tenantId, string $slug, string $name, int $level, ?string $description = null): Role
    {
        $role = Role::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->first() ?? new Role;

        $role->forceFill([
            'tenant_id' => $tenantId,
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'level' => $level,
            'is_system' => true,
        ])->save();

        return $role;
    }

    /**
     * One-time bootstrap for data created before RBAC existed: a tenant user
     * with zero roles cannot do anything once permission checks are enforced,
     * so the first such user per school is promoted to School Admin.
     */
    private function bootstrapUnassignedUsers(): void
    {
        User::query()
            ->whereNotNull('tenant_id')
            ->doesntHave('roles')
            ->get()
            ->groupBy('tenant_id')
            ->each(function ($users, $tenantId) {
                $adminRole = Role::query()
                    ->where('tenant_id', $tenantId)
                    ->where('slug', Role::SCHOOL_ADMIN)
                    ->first();

                if ($adminRole === null) {
                    return;
                }

                /** @var User $first */
                $first = $users->sortBy('id')->first();
                $first->attachRoles($adminRole);
            });
    }
}
