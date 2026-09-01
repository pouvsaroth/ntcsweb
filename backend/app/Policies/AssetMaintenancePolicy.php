<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetMaintenance;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AssetMaintenancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_MAINTENANCE_VIEW);
    }

    public function view(User $user, AssetMaintenance $maintenance): bool
    {
        return $user->hasPermission(Permissions::ASSET_MAINTENANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_MAINTENANCE_CREATE);
    }

    public function update(User $user, AssetMaintenance $maintenance): bool
    {
        return $user->hasPermission(Permissions::ASSET_MAINTENANCE_UPDATE);
    }
}
