<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Position;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Position uses BelongsToTenant, so its global scope already makes a
 * cross-tenant Position unreachable before a policy method runs — these only
 * check the permission itself.
 */
class PositionPolicy
{
    public function viewAny(User $user): bool
    {
        // The staff form picks a Position, so whoever adds or edits staff
        // may list them (names only) without the Positions menu itself.
        return $user->hasPermission(Permissions::POSITIONS_VIEW)
            || $user->hasPermission(Permissions::STAFF_CREATE)
            || $user->hasPermission(Permissions::STAFF_UPDATE);
    }

    public function view(User $user, Position $position): bool
    {
        return $user->hasPermission(Permissions::POSITIONS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::POSITIONS_CREATE);
    }

    public function update(User $user, Position $position): bool
    {
        return $user->hasPermission(Permissions::POSITIONS_UPDATE);
    }

    public function delete(User $user, Position $position): bool
    {
        return $user->hasPermission(Permissions::POSITIONS_DELETE);
    }
}
