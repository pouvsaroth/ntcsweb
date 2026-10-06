<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Department;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * One department list, two ways in: a configuration record under the Assets
 * module (see AssetCategoryPolicy's docblock), and a tab of HRM >
 * Organization Management — so either permission set reaches it.
 */
class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_VIEW) || $user->hasPermission(Permissions::ORGANIZATION_VIEW);
    }

    public function view(User $user, Department $department): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ASSETS_CREATE) || $user->hasPermission(Permissions::ORGANIZATION_CREATE);
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::ASSETS_UPDATE) || $user->hasPermission(Permissions::ORGANIZATION_UPDATE);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::ASSETS_DELETE) || $user->hasPermission(Permissions::ORGANIZATION_DELETE);
    }
}
