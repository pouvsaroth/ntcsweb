<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AssetRepair;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AssetRepairPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_VIEW);
    }

    public function view(User $user, AssetRepair $repair): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_CREATE);
    }

    public function update(User $user, AssetRepair $repair): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_UPDATE);
    }

    public function complete(User $user, AssetRepair $repair): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_COMPLETE);
    }
}
