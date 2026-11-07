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
        // Send to repair and Schedule maintenance pick a shop.
        return $user->hasAnyPermission(Permissions::REPAIR_SHOPS_VIEW, Permissions::ASSET_REPAIRS_VIEW, Permissions::ASSET_REPAIRS_CREATE, Permissions::ASSET_MAINTENANCE_CREATE, Permissions::ASSET_MAINTENANCE_UPDATE);
    }

    public function view(User $user, RepairShop $repairShop): bool
    {
        return $user->hasAnyPermission(Permissions::REPAIR_SHOPS_VIEW, Permissions::ASSET_REPAIRS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::REPAIR_SHOPS_CREATE);
    }

    public function update(User $user, RepairShop $repairShop): bool
    {
        return $user->hasPermission(Permissions::REPAIR_SHOPS_UPDATE);
    }

    public function delete(User $user, RepairShop $repairShop): bool
    {
        return $user->hasPermission(Permissions::REPAIR_SHOPS_DELETE);
    }
}
