<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetCategory;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** A configuration record under the Assets module — gated by the same assets.* permissions as an Asset itself, not a separate permission group. */
class AssetCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasAnyPermission(Permissions::ASSET_CATEGORIES_VIEW, Permissions::ASSETS_VIEW);
    }

    public function view(User $user, AssetCategory $category): bool
    {
        return $user->hasAnyPermission(Permissions::ASSET_CATEGORIES_VIEW, Permissions::ASSETS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_CATEGORIES_CREATE);
    }

    public function update(User $user, AssetCategory $category): bool
    {
        return $user->hasPermission(Permissions::ASSET_CATEGORIES_UPDATE);
    }

    public function delete(User $user, AssetCategory $category): bool
    {
        return $user->hasPermission(Permissions::ASSET_CATEGORIES_DELETE);
    }
}
