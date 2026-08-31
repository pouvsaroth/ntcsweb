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
     * Gates the "take attendance" screen for this specific class: a holder
     * of `attendance.create`/`attendance.update` may act on any class UNLESS
     * their account is itself a teacher — in which case it must be a class
     * they actually teach. A school-admin or office-staff account (no linked
     * Teacher record) is never restricted this way.
     */
    public function recordAttendance(User $user, SchoolClass $class): bool
    {
        if (! $user->hasPermission(Permissions::ATTENDANCE_CREATE) && ! $user->hasPermission(Permissions::ATTENDANCE_UPDATE)) {
            return false;
        }

        $teacher = $user->teacher;

        return $teacher === null || $teacher->classes()->whereKey($class->getKey())->exists();
    }
}
