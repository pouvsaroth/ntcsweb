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
     * of `attendance.create`/`attendance.update` may act on any class, UNLESS
     * they're teacher-tier (no `classes.update`) — in which case it must be a
     * class they're actually assigned to teach. `classes.update` (school-admin
     * only, via $academicManagement) is the signal, not "has a linked Staff
     * record": a school admin who is *also* listed as staff (a common
     * real-world setup — e.g. the director) must still be able to record
     * attendance for every class, not just the ones they personally teach.
     *
     * A class with no teacher assigned yet (`teacher_id` null) is open to any
     * teacher-tier account too — otherwise it would be permanently locked out
     * of attendance for everyone but an admin until someone assigns it a
     * teacher via Classes > Edit.
     */
    public function recordAttendance(User $user, SchoolClass $class): bool
    {
        if (! $user->hasPermission(Permissions::ATTENDANCE_CREATE) && ! $user->hasPermission(Permissions::ATTENDANCE_UPDATE)) {
            return false;
        }

        if ($user->hasPermission(Permissions::CLASSES_UPDATE) || $class->teacher_id === null) {
            return true;
        }

        $staff = $user->staff;

        return $staff === null || $staff->classes()->whereKey($class->getKey())->exists();
    }
}
