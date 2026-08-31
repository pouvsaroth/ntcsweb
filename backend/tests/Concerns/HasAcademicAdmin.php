<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Shared setup for the academic-domain feature tests (teachers, students,
 * classrooms, books, classes, enrollments): a tenant, a role holding exactly
 * the permissions the test asks for, and a user acting as that role —
 * signed in and tenant-scoped via actingAsTenantUser().
 */
trait HasAcademicAdmin
{
    protected Tenant $tenant;

    protected User $admin;

    /**
     * @param  list<string>  $permissions
     */
    protected function actingAsAdminWithPermissions(array $permissions): User
    {
        $this->tenant = Tenant::factory()->create();

        app(PermissionRegistry::class)->sync();

        $role = Role::factory()->forTenant($this->tenant)->create();
        $role->permissions()->attach(Permission::query()->whereIn('slug', $permissions)->pluck('id'));

        $this->admin = User::factory()->forTenant($this->tenant)->create();
        $this->admin->attachRoles($role);

        $this->actingAsTenantUser($this->admin);

        // A real tenant always has its RolePermissionSeeder-provided system
        // roles by the time anyone can act in it. Student/Staff creation now
        // looks one of those up (see StudentController/StaffController), so
        // fixture tenants need the same guarantee — not the whole seeder
        // (which also touches every other tenant in the database), just the
        // one row that would otherwise be missing.
        Role::factory()->forTenant($this->tenant)->system()->create([
            'slug' => Role::STUDENT,
            'name' => 'Student',
            'level' => Role::LEVELS[Role::STUDENT],
        ]);

        return $this->admin;
    }

    /**
     * BelongsToTenant's write guard refuses to persist an explicit tenant_id
     * that doesn't match the ambient TenantContext — by design, the same
     * protection that stops a real request from writing into another
     * school's data. Building "another school's" fixture in a test hits that
     * exact guard, so it has to run in platform mode, same as any other
     * deliberate cross-tenant write.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    protected function createForOtherTenant(Closure $callback): mixed
    {
        return app(TenantContext::class)->withoutTenancy($callback);
    }
}
