<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Building;
use App\Models\User;
use App\Support\Authorization\Permissions;

class BuildingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BUILDINGS_VIEW);
    }

    public function view(User $user, Building $building): bool
    {
        return $user->hasPermission(Permissions::BUILDINGS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BUILDINGS_CREATE);
    }

    public function update(User $user, Building $building): bool
    {
        return $user->hasPermission(Permissions::BUILDINGS_UPDATE);
    }

    public function delete(User $user, Building $building): bool
    {
        return $user->hasPermission(Permissions::BUILDINGS_DELETE);
    }
}
