<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Two independent gates on every check:
 *
 *   1. the acting user holds the permission, and
 *   2. the target belongs to the same school.
 *
 * The tenant check is repeated here even though TenantScope and
 * EnsureTenantMatchesUser already constrain the request. Policies are also
 * reached from console commands and queued jobs, where no middleware ran, so
 * they cannot assume the boundary was enforced upstream.
 *
 * Platform super admins never reach these methods — Gate::before grants them
 * everything first.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USERS_VIEW);
    }

    public function view(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $this->sameTenant($actor, $target)
            && $actor->hasPermission(Permissions::USERS_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission(Permissions::USERS_CREATE);
    }

    public function update(User $actor, User $target): bool
    {
        if ($actor->is($target)) {
            return true;
        }

        return $this->sameTenant($actor, $target)
            && $actor->hasPermission(Permissions::USERS_UPDATE)
            && $this->outranks($actor, $target);
    }

    public function delete(User $actor, User $target): bool
    {
        // Deleting yourself would leave a school with no way back in if you
        // were its last administrator.
        if ($actor->is($target)) {
            return false;
        }

        return $this->sameTenant($actor, $target)
            && $actor->hasPermission(Permissions::USERS_DELETE)
            && $this->outranks($actor, $target);
    }

    public function restore(User $actor, User $target): bool
    {
        return $this->delete($actor, $target);
    }

    /**
     * A school admin may not act on an account that ranks at or above their
     * own, so peers cannot suspend or demote each other and nobody can escalate
     * by editing someone more privileged.
     */
    private function outranks(User $actor, User $target): bool
    {
        return $actor->roleLevel() > $target->roleLevel();
    }

    private function sameTenant(User $actor, User $target): bool
    {
        // Both NULL would mean two platform accounts, which only a super admin
        // reaches — and they never get here.
        return $actor->tenant_id !== null && $actor->tenant_id === $target->tenant_id;
    }
}
