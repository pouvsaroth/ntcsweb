<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ClassroomTable;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Reuses Classroom's own permissions -- a table is a lightweight sub-resource
 * of its classroom, not worth a separate permission set.
 */
class ClassroomTablePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_VIEW);
    }

    public function view(User $user, ClassroomTable $classroomTable): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_CREATE);
    }

    public function update(User $user, ClassroomTable $classroomTable): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_UPDATE);
    }

    public function delete(User $user, ClassroomTable $classroomTable): bool
    {
        return $user->hasPermission(Permissions::CLASSROOMS_DELETE);
    }
}
