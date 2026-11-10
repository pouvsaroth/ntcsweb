<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Academic\SchoolDaysOff;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Attendance not taken": once a class's last session of the day ended
 * GRACE_MINUTES ago and nobody has saved attendance for it, its teachers
 * (main + assistant) and the school admins are told — once per class per
 * day. Run every 15 minutes by `attendance:remind-not-taken`, inside one
 * school's tenant context.
 */
final class AttendanceReminderService
{
    /** Minutes after the class ends before the reminder goes out. */
    public const GRACE_MINUTES = 30;

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return int how many classes were reminded about */
    public function remindForTenant(Tenant $tenant): int
    {
        $now = Carbon::now($tenant->timezone ?: 'Asia/Phnom_Penh');
        $today = $now->toDateString();

        // Holidays and School Calendar closing days have no classes.
        if (SchoolDaysOff::on($now) !== null) {
            return 0;
        }

        $classes = SchoolClass::query()
            ->active()
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas('schedules', fn (Builder $q) => $q->where('day_of_week', $now->dayOfWeekIso))
            ->with(['schedules', 'teachingStaff.user'])
            ->get();

        $admins = null;
        $reminded = 0;

        foreach ($classes as $class) {
            $endTime = $class->schedules
                ->filter(fn (ClassSchedule $s) => (int) $s->day_of_week === $now->dayOfWeekIso)
                ->max(fn (ClassSchedule $s) => substr((string) $s->end_time, 0, 5));

            if ($now->lt(Carbon::parse("{$today} {$endTime}", $now->timezone)->addMinutes(self::GRACE_MINUTES))) {
                continue;
            }

            $key = "{$class->id}:{$today}";

            if ($this->alreadyHandled($class, $today, $key)) {
                continue;
            }

            $recipients = $class->teachingStaff
                ->map(fn (Staff $staff) => $staff->user)
                ->filter(fn ($user) => $user !== null && $user->isActive())
                ->merge($admins ??= $this->notifications->usersWithRole(Role::SCHOOL_ADMIN));

            $this->notifications->notifyMany($recipients, NotificationType::ATTENDANCE_NOT_TAKEN, [
                'class_id' => $class->id,
                'class_name' => $class->name,
                'date' => $now->format('d-m-Y'),
                'end_time' => $endTime,
                'reminder_key' => $key,
            ], link: "/admin/attendance?class_id={$class->id}");

            $reminded++;
        }

        return $reminded;
    }

    /** Attendance already saved, nobody to mark, or the reminder already sent. */
    private function alreadyHandled(SchoolClass $class, string $today, string $key): bool
    {
        return AttendanceRecord::query()->where('class_id', $class->id)->whereDate('date', $today)->exists()
            || ! Enrollment::query()->active()->where('class_id', $class->id)->exists()
            || UserNotification::query()
                ->where('type', NotificationType::ATTENDANCE_NOT_TAKEN)
                ->where('data->reminder_key', $key)
                ->exists();
    }
}
