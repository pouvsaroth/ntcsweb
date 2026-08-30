<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Staff;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Staff uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Staff row unreachable before a policy method runs — these only
 * check the permission itself.
 */
class StaffPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_VIEW);
    }

    public function view(User $user, Staff $staff): bool
    {
        return $user->hasPermission(Permissions::STAFF_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_CREATE);
    }

    public function update(User $user, Staff $staff): bool
    {
        return $user->hasPermission(Permissions::STAFF_UPDATE);
    }

    public function delete(User $user, Staff $staff): bool
    {
        return $user->hasPermission(Permissions::STAFF_DELETE);
    }
}
