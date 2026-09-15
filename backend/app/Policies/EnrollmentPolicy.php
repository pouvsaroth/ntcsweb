<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Enrollment;
use App\Models\User;
use App\Support\Authorization\Permissions;

class EnrollmentPolicy
{
    /**
     * Viewing is also open to anyone who can take attendance — a class's
     * roster (ClassStudents.vue) is how a teacher/assistant actually reaches
     * the "Attendance" action, and a role built from Classes + Attendance
     * alone (deliberately excluding the Enrollments module, so the roster's
     * transfer/status-change actions stay hidden — see
     * ClassStudents.vue's own permission checks) must not 403 on the one
     * read this whole flow depends on. Mirrors
     * SchoolClassPolicy::recordAttendance()'s own "any attendance-permission
     * holder, any class" scope, so this isn't a new looser precedent.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_VIEW) || $this->canViewForAttendance($user);
    }

    public function view(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_VIEW) || $this->canViewForAttendance($user);
    }

    private function canViewForAttendance(User $user): bool
    {
        return $user->hasPermission(Permissions::ATTENDANCE_VIEW)
            || $user->hasPermission(Permissions::ATTENDANCE_CREATE)
            || $user->hasPermission(Permissions::ATTENDANCE_UPDATE);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CREATE);
    }

    public function update(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_UPDATE);
    }

    public function delete(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_DELETE);
    }

    public function cancel(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CANCEL);
    }

    public function transfer(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_TRANSFER);
    }

    public function changeStatus(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CHANGE_STATUS);
    }

    /**
     * Deliberately independent of transfer() — a school can grant a Teacher
     * this without also handing them the ability to move a student to a
     * different class or course. See Permissions::ENROLLMENTS_CHANGE_TABLE.
     */
    public function changeTable(User $user, Enrollment $enrollment): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CHANGE_TABLE);
    }

    /**
     * The table picker (SchoolClassController::availableTables()) is shared
     * by every flow that ever needs to know which seats are free in a class
     * — full transfer, table-only reseating, or a brand new enrollment —
     * so it opens to whichever of those abilities the user actually holds.
     */
    public function viewAvailableTables(User $user): bool
    {
        return $user->hasPermission(Permissions::ENROLLMENTS_CREATE)
            || $user->hasPermission(Permissions::ENROLLMENTS_TRANSFER)
            || $user->hasPermission(Permissions::ENROLLMENTS_CHANGE_TABLE);
    }
}
