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
        // Class, exam application and exam schedule forms pick a room.
        return $user->hasAnyPermission(Permissions::CLASSROOMS_VIEW, Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE, Permissions::EXAM_APPLICATIONS_CREATE, Permissions::EXAM_APPLICATIONS_UPDATE, Permissions::EXAM_SCHEDULES_CREATE, Permissions::EXAM_SCHEDULES_UPDATE);
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
