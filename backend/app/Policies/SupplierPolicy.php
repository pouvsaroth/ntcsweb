<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** A configuration record under the Assets module — see AssetCategoryPolicy's docblock. */
class SupplierPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW);
    }

    public function view(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE);
    }

    public function update(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE);
    }

    public function delete(User $user, Supplier $supplier): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE);
    }
}
