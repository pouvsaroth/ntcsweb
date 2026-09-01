<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Support\Authorization\Permissions;

/** A configuration record under the Assets module — see AssetCategoryPolicy's docblock. */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW);
    }

    public function view(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE);
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE);
    }
}
