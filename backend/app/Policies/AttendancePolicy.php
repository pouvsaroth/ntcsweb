<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use App\Support\Authorization\Permissions;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::ATTENDANCE_VIEW);
    }

    /**
     * A student may always view their own attendance record — identity-based,
     * no permission grant needed (see InvoicePolicy for the same pattern).
     * A teacher may view records for a class they teach even without the
     * broad `attendance.view` permission — see SchoolClassPolicy::teaches().
     */
    public function view(User $user, AttendanceRecord $record): bool
    {
        if ($user->hasPermission(Permissions::ATTENDANCE_VIEW) || $user->student?->id === $record->student_id) {
            return true;
        }

        $teacher = $user->teacher;

        return $teacher !== null && $teacher->classes()->whereKey($record->class_id)->exists();
    }
}
