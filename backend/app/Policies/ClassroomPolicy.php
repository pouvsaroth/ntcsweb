<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Classroom;
use App\Models\User;
use App\Support\Authorization\Permissions;

class ClassroomPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_VIEW);
    }

    public function view(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_CREATE);
    }

    public function update(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_UPDATE);
    }

    public function delete(User $user, Classroom $classroom): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_DELETE);
    }
}
