<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Role;
use App\Support\Authorization\PermissionRegistry;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * RBAC for the User model.
 *
 * Assignment lives in RoleService, not here — putting the tenant and hierarchy
 * checks on the model would make them easy to bypass with a raw
 * `$user->roles()->attach()`. What is here is the read side plus low-level
 * mutators that always bust the permission cache.
 */
trait HasRolesAndPermissions
{
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * @return list<string>
     */
    public function permissionSlugs(): array
    {
        return app(PermissionRegistry::class)->forUser($this);
    }

    public function hasPermission(string $permission): bool
    {
        // Super admins are granted everything by Gate::before; short-circuiting
        // here too keeps direct hasPermission() calls consistent with can().
        if ($this->isSuperAdmin()) {
            return true;
        }

        return app(PermissionRegistry::class)->has($this, $permission);
    }

    public function hasAnyPermission(string ...$permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, Role>
     */
    public function cachedRoles(): Collection
    {
        if (! $this->relationLoaded('roles')) {
            $this->load('roles');
        }

        return $this->getRelation('roles');
    }

    public function hasRole(string ...$slugs): bool
    {
        return $this->cachedRoles()->contains(
            fn (Role $role) => in_array($role->slug, $slugs, true)
        );
    }

    /**
     * A platform super admin: no school of their own, plus the platform role.
     *
     * Both halves matter. tenant_id alone would let a half-created account
     * escape scoping; the role alone would let a school create its own
     * "super-admin" and reach across tenants.
     */
    public function isSuperAdmin(): bool
    {
        return $this->tenant_id === null
            && $this->cachedRoles()->contains(fn (Role $role) => $role->isSuperAdmin());
    }

    /**
     * Highest role level held, used for the "cannot manage your equals or your
     * betters" rule in RoleService.
     */
    public function roleLevel(): int
    {
        return (int) $this->cachedRoles()->max('level');
    }

    /**
     * Low-level assignment. Prefer RoleService, which validates tenant and
     * hierarchy first.
     *
     * @internal
     */
    public function attachRoles(Role ...$roles): void
    {
        $this->roles()->syncWithoutDetaching(array_map(fn (Role $r) => $r->getKey(), $roles));
        $this->forgetPermissionCache();
    }

    /**
     * @internal
     */
    public function detachRoles(Role ...$roles): void
    {
        $this->roles()->detach(array_map(fn (Role $r) => $r->getKey(), $roles));
        $this->forgetPermissionCache();
    }

    public function forgetPermissionCache(): void
    {
        $this->unsetRelation('roles');

        app(PermissionRegistry::class)->invalidate($this->tenant_id);
    }
}
