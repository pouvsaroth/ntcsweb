<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RepairShop;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** Gated by the Repair permission group, since repair shops only matter alongside repairs. */
class RepairShopPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_VIEW);
    }

    public function view(User $user, RepairShop $repairShop): bool
    {
        return $user->hasPermission(Permissions::ASSET_REPAIRS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE);
    }

    public function update(User $user, RepairShop $repairShop): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE);
    }

    public function delete(User $user, RepairShop $repairShop): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE);
    }
}
