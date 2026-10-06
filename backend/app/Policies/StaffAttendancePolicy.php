<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Attendance & Time's set-up and records (shifts, work schedules,
 * holidays, staff attendance): view to read, manage to change. A staff
 * member's own check-in doesn't go through this — it's identity-gated.
 */
class StaffAttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function view(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function update(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }

    public function delete(User $user, Model $record): bool
    {
        return $user->hasPermission(Permissions::STAFF_ATTENDANCE_MANAGE);
    }
}
