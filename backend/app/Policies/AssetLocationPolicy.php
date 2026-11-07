<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetLocation;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** A configuration record under the Assets module — see AssetCategoryPolicy's docblock. */
class AssetLocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(Permissions::ASSET_LOCATIONS_VIEW, Permissions::ASSETS_VIEW);
    }

    public function view(User $user, AssetLocation $location): bool
    {
        return $user->hasAnyPermission(Permissions::ASSET_LOCATIONS_VIEW, Permissions::ASSETS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_LOCATIONS_CREATE);
    }

    public function update(User $user, AssetLocation $location): bool
    {
        return $user->hasPermission(Permissions::ASSET_LOCATIONS_UPDATE);
    }

    public function delete(User $user, AssetLocation $location): bool
    {
        return $user->hasPermission(Permissions::ASSET_LOCATIONS_DELETE);
    }
}
