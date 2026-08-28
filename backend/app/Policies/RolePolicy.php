<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Support\Authorization\Permissions;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permissions::ROLES_VIEW);
    }

    public function view(User $actor, Role $role): bool
    {
        return $this->sameTenant($actor, $role)
            && $actor->hasPermission(Permissions::ROLES_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permissions::ROLES_CREATE);
    }

    public function update(User $actor, Role $role): bool
    {
        // System roles are seeded and referenced by slug across the codebase;
        // letting a school rewrite them would break those assumptions.
        if ($role->is_system) {
            return false;
        }

        return $this->sameTenant($actor, $role)
            && $actor->hasPermission(Permissions::ROLES_UPDATE)
            && $this->outranks($actor, $role);
    }

    public function delete(User $actor, Role $role): bool
    {
        if ($role->is_system) {
            return false;
        }

        return $this->sameTenant($actor, $role)
            && $actor->hasPermission(Permissions::ROLES_DELETE)
            && $this->outranks($actor, $role);
    }

    /**
     * Granting a role you do not outrank is privilege escalation: it is how a
     * school admin would make themselves, or a friend, a super admin.
     */
    public function assign(User $actor, Role $role): bool
    {
        return $this->sameTenant($actor, $role)
            && $actor->hasPermission(Permissions::ROLES_ASSIGN)
            && $this->outranks($actor, $role);
    }

    private function outranks(User $actor, Role $role): bool
    {
        return $actor->roleLevel() > $role->level;
    }

    private function sameTenant(User $actor, Role $role): bool
    {
        // Platform roles (tenant_id NULL) are unreachable for school staff.
        return $actor->tenant_id !== null && $actor->tenant_id === $role->tenant_id;
    }
}
