<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassMakeupSession;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherSubstitution;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Academic\ClassNotHeld;
use App\Support\Academic\SchoolDaysOff;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * "Attendance not taken": once a class's last session of the day ended
 * GRACE_MINUTES ago and nobody has saved attendance for it, its teachers
 * (main + assistant) and the school admins are told — once per class per
 * day. Run every 15 minutes by `attendance:remind-not-taken`, inside one
 * school's tenant context. A make-up session that day (Teacher > Substitute)
 * counts as a session: its end can be the last one, its teacher is told, and
 * it needs attendance even when the class's own sessions were not held.
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
            ->where(fn (Builder $q) => $q
                ->whereHas('schedules', fn (Builder $q) => $q->where('day_of_week', $now->dayOfWeekIso))
                ->orWhereIn('id', ClassMakeupSession::query()->whereDate('date', $today)->select('class_id')))
            ->with(['schedules', 'teachingStaff.user'])
            ->get();

        $makeups = ClassMakeupSession::query()->with('staff.user')->whereDate('date', $today)->get()->groupBy('class_id');

        $admins = null;
        $reminded = 0;

        foreach ($classes as $class) {
            $classMakeups = $makeups->get($class->id, collect());
            // Teacher away and nobody covers (Teacher > Substitute): no class, no
            // attendance — unless a make-up session is on today.
            $notHeld = ClassNotHeld::on($class, $today);
            if ($notHeld && $classMakeups->isEmpty()) {
                continue;
            }

            $ends = $classMakeups->map(fn (ClassMakeupSession $m) => substr((string) $m->end_time, 0, 5));
            if (! $notHeld) {
                $ends = $ends->merge($class->schedules
                    ->filter(fn (ClassSchedule $s) => (int) $s->day_of_week === $now->dayOfWeekIso)
                    ->map(fn (ClassSchedule $s) => substr((string) $s->end_time, 0, 5)));
            }
            $endTime = $ends->max();

            if ($endTime === null || $now->lt(Carbon::parse("{$today} {$endTime}", $now->timezone)->addMinutes(self::GRACE_MINUTES))) {
                continue;
            }

            $key = "{$class->id}:{$today}";

            if ($this->alreadyHandled($class, $today, $key)) {
                continue;
            }

            // Teachers covering this class today (Teacher > Substitute) are told too.
            $substitutes = TeacherSubstitution::query()->with('substituteStaff.user')
                ->where('class_id', $class->id)->whereDate('date', $today)->whereNotNull('substitute_staff_id')
                ->get()->map(fn (TeacherSubstitution $s) => $s->substituteStaff);

            $recipients = $class->teachingStaff->toBase()
                ->merge($substitutes)
                // And whoever teaches today's make-up session.
                ->merge($classMakeups->map(fn (ClassMakeupSession $m) => $m->staff))
                ->filter()
                ->map(fn (Staff $staff) => $staff->user)
                ->filter(fn ($user) => $user !== null && $user->isActive())
                ->merge($admins ??= $this->notifications->usersWithRole(Role::SCHOOL_ADMIN))
                ->unique(fn ($user) => $user->getKey())
                ->values();

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
