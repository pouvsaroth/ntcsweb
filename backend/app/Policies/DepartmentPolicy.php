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
        // Asset and staff forms pick a department from the list.
        return $user->hasAnyPermission(Permissions::DEPARTMENTS_VIEW, Permissions::ORGANIZATION_VIEW, Permissions::ASSETS_VIEW);
    }

    public function view(User $user, Department $department): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::DEPARTMENTS_CREATE);
    }

    public function update(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::DEPARTMENTS_UPDATE);
    }

    public function delete(User $user, Department $department): bool
    {
        return $user->hasPermission(Permissions::DEPARTMENTS_DELETE);
    }
}
