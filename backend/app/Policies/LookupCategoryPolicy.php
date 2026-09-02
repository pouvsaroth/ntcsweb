<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\LookupCategory;
use App\Models\User;
use App\Support\Authorization\Permissions;

class LookupCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function view(User $user, LookupCategory $category): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_CREATE);
    }

    public function update(User $user, LookupCategory $category): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_UPDATE);
    }

    public function delete(User $user, LookupCategory $category): bool
    {
        return $user->hasPermission(Permissions::BASE_DATA_DELETE);
    }
}
