<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Holiday;
use App\Models\Shift;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Model;

/**
 * HRM > Time & Attendance's set-up and records: staff-attendance.view to read;
 * Add / Update / Delete per tab — Check-in / check-out (check-ins.*), Shift
 * (shifts.*), Staff shift (staff-shifts.*), Holidays (holidays.*). A staff
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

    /** $class names the tab: authorize('create', [Shift::class, Shift::class]). */
    public function create(User $user, ?string $class = null): bool
    {
        $tab = self::tab($class);

        return $tab !== null && $user->hasPermission("{$tab}.create");
    }

    public function update(User $user, Model $record): bool
    {
        $tab = self::tab($record::class);

        return $tab !== null && $user->hasPermission("{$tab}.update");
    }

    public function delete(User $user, Model $record): bool
    {
        $tab = self::tab($record::class);

        return $tab !== null && $user->hasPermission("{$tab}.delete");
    }

    private static function tab(?string $class): ?string
    {
        return match ($class) {
            StaffAttendance::class => 'check-ins',
            Shift::class => 'shifts',
            WorkSchedule::class => 'staff-shifts',
            Holiday::class => 'holidays',
            default => null,
        };
    }
}
