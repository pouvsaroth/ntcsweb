<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Support\Authorization\Permissions;

/**
 * Attendance corrections: staff-attendance view reads them; the decision is
 * the attendance-corrections approve/reject permission
 * (ApprovalFlow::mayDecide() decides instead when the item has a flow).
 */
class AttendanceCorrectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function view(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function delete(User $user, AttendanceCorrection $correction): bool
    {
        return $correction->status === AttendanceCorrection::STATUS_PENDING && $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function approve(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission(Permissions::ATTENDANCE_CORRECTIONS_APPROVE);
    }

    public function reject(User $user, AttendanceCorrection $correction): bool
    {
        return $user->hasPermission(Permissions::ATTENDANCE_CORRECTIONS_REJECT);
    }
}
