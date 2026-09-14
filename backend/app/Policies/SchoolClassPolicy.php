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

    /**
     * Gates the "take attendance" screen: any holder of `attendance.create`/
     * `attendance.update` may record attendance for any class, not just ones
     * they're personally assigned to teach — a school may want any teacher
     * or assistant able to cover any class (a substitute, a shared room,
     * etc.), and the Teacher/assistant role itself is already the gate that
     * decides who gets those two permissions in the first place. `$class` is
     * unused but kept so this still resolves as a model policy for
     * `Gate::authorize('recordAttendance', $class)`.
     */
    public function recordAttendance(User $user, SchoolClass $class): bool
    {
        return $user->hasPermission(Permissions::ATTENDANCE_CREATE) || $user->hasPermission(Permissions::ATTENDANCE_UPDATE);
    }
}
