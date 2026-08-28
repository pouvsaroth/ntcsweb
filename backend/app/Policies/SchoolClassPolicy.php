<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Authorization\Permissions;

class SchoolClassPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSES_VIEW);
    }

    public function view(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission(Permissions::CLASSES_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSES_CREATE);
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission(Permissions::CLASSES_UPDATE);
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission(Permissions::CLASSES_DELETE);
    }
}
