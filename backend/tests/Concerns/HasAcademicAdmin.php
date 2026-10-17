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

    private ?int $adminCount = null;

    /**
     * The test's school, made (once) and set as the current school — for
     * fixtures created before anyone signs in. Row-level security refuses a
     * school row written with no school set; a later
     * actingAsAdminWithPermissions() joins this same school.
     */
    protected function inSchool(): Tenant
    {
        $this->tenant ??= Tenant::factory()->create();
        $this->actingInTenant($this->tenant);

        return $this->tenant;
    }

    /**
     * @param  list<string>  $permissions
     */
    protected function actingAsAdminWithPermissions(array $permissions): User
    {
        // A second call in the same test is another admin of the SAME school
        // (e.g. "an enrollments-only role can reseat the student the first
        // admin enrolled"). It used to create a fresh school each time, which
        // only worked while every test school shared one unprotected database:
        // the second admin was quietly acting on another school's rows.
        // Row-level security (stage 4a) rightly answers 404 to that now.
        $firstInThisSchool = ! isset($this->tenant);
        $this->tenant ??= Tenant::factory()->create();
        $this->adminCount = ($this->adminCount ?? 0) + 1;

        app(PermissionRegistry::class)->sync();

        // A fixed name/slug, not RoleFactory's random fake()->jobTitle() —
        // that pool includes real job titles like "Accountant", and several
        // tests in this suite explicitly create a role literally named
        // "Accountant" for their own tenant. A random collision with this
        // tenant's own admin role would fail on the (tenant_id, slug)
        // unique constraint depending on the run's random seed.
        $suffix = $this->adminCount === 1 ? '' : '-'.$this->adminCount;
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Test Admin'.$suffix, 'slug' => 'test-admin'.$suffix]);
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
        if ($firstInThisSchool) {
            Role::factory()->forTenant($this->tenant)->system()->create([
                'slug' => Role::STUDENT,
                'name' => 'Student',
                'level' => Role::LEVELS[Role::STUDENT],
            ]);
        }

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
