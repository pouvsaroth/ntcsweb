<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\TeacherSubstitution;
use App\Models\Tenant;
use App\Models\UserNotification;
use App\Services\Notifications\NotificationService;
use App\Support\Academic\SchoolDaysOff;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Teacher away from a class today (docs/hrm/teachers.md): tells whoever can
 * arrange substitutes (Teachers > Substitute), linking to that form filled in.
 * Run every 5 minutes by `teachers:alert-absent`, inside one school's context.
 *
 * - teacher_on_leave_class: the teacher has approved leave today and a class
 *   session of theirs today has not ended — sent as soon as it is seen.
 * - teacher_not_checked_in_class: GRACE_MINUTES after a session started the
 *   teacher has still not checked in (Attendance & Time). Only for teachers
 *   who checked in at least once in the last USES_CHECK_IN_DAYS days, so a
 *   school that does not use check-in is not flooded.
 *
 * Nothing is sent when a Substitute row already covers the teacher for that
 * session, when student attendance is already saved today, on days off, or
 * when the class has no students. Once per class, teacher, day and session.
 */
final class TeacherAbsenceAlertService
{
    /** Minutes after a session starts before "not checked in" goes out. */
    public const GRACE_MINUTES = 10;

    /** A teacher with no check-in in this many days does not use check-in. */
    public const USES_CHECK_IN_DAYS = 30;

    public function __construct(private readonly NotificationService $notifications) {}

    /** @return int how many alerts were sent (one per class session and teacher) */
    public function alertForTenant(Tenant $tenant): int
    {
        $now = Carbon::now($tenant->timezone ?: 'Asia/Phnom_Penh');
        $today = $now->toDateString();
        $nowHm = $now->format('H:i');

        if (SchoolDaysOff::on($now) !== null) {
            return 0;
        }

        $classes = SchoolClass::query()
            ->active()
            ->where(fn (Builder $q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $today))
            ->where(fn (Builder $q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $today))
            ->whereHas('schedules', fn (Builder $q) => $q->where('day_of_week', $now->dayOfWeekIso))
            ->whereHas('enrollments', fn (Builder $q) => $q->active())
            ->whereNotIn('id', AttendanceRecord::query()->whereDate('date', $today)->select('class_id'))
            ->with(['schedules', 'teachers:id'])
            ->get();
        if ($classes->isEmpty()) {
            return 0;
        }

        $subs = TeacherSubstitution::query()->whereDate('date', $today)->get()->groupBy('class_id');
        $staffIds = $classes->flatMap(fn (SchoolClass $c) => $c->teachers->modelKeys())
            ->merge($classes->flatMap(fn (SchoolClass $c) => $c->schedules->pluck('staff_id')))
            ->filter()->unique()->values()->all();
        $staff = Staff::query()->whereIn('id', $staffIds)->get()->keyBy('id');
        $onLeave = LeaveRequest::query()->whereIn('staff_id', $staffIds)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)
            ->pluck('staff_id')->map(fn ($id) => (int) $id)->unique()->all();
        $checkIns = StaffAttendance::query()->whereIn('staff_id', $staffIds)->whereNotNull('check_in_at')
            ->whereDate('date', '>=', $now->copy()->subDays(self::USES_CHECK_IN_DAYS)->toDateString())
            ->get(['staff_id', 'date'])
            ->groupBy('staff_id');

        $recipients = null;
        $sent = 0;

        foreach ($classes as $class) {
            $sessions = $class->schedules->filter(fn (ClassSchedule $s) => (int) $s->day_of_week === $now->dayOfWeekIso)->sortBy('start_time');

            foreach ($sessions as $session) {
                $start = substr((string) $session->start_time, 0, 5);
                $end = substr((string) $session->end_time, 0, 5);
                if ($end <= $nowHm) {
                    continue;
                }

                $teacherIds = $session->staff_id !== null ? [(int) $session->staff_id] : $class->teachers->modelKeys();
                foreach ($teacherIds as $teacherId) {
                    $teacher = $staff->get($teacherId);
                    if ($teacher === null || $this->handled($subs->get($class->id, collect()), $teacherId, $start, $end)) {
                        continue;
                    }

                    $mine = $checkIns->get($teacherId, collect());
                    $type = match (true) {
                        in_array($teacherId, $onLeave, true) => NotificationType::TEACHER_ON_LEAVE_CLASS,
                        $mine->isNotEmpty()
                            && ! $mine->contains(fn (StaffAttendance $a) => $a->date->toDateString() === $today)
                            && $now->gte(Carbon::parse("{$today} {$start}", $now->timezone)->addMinutes(self::GRACE_MINUTES)) => NotificationType::TEACHER_NOT_CHECKED_IN_CLASS,
                        default => null,
                    };
                    if ($type === null) {
                        continue;
                    }

                    $key = "{$class->id}:{$teacherId}:{$today}:{$start}";
                    if (UserNotification::query()->whereIn('type', [NotificationType::TEACHER_ON_LEAVE_CLASS, NotificationType::TEACHER_NOT_CHECKED_IN_CLASS])->where('data->alert_key', $key)->exists()) {
                        continue;
                    }

                    $recipients ??= $this->recipients();
                    $this->notifications->notifyMany($recipients, $type, [
                        'class_id' => $class->id,
                        'class_name' => $class->name,
                        'teacher_name' => $teacher->fullName(),
                        'date' => $now->format('d-m-Y'),
                        'start_time' => $start,
                        'alert_key' => $key,
                    ], link: '/admin/teachers/substitutes?'.http_build_query([
                        'class_id' => $class->id,
                        'date' => $today,
                        'absent_staff_id' => $teacherId,
                        'start_time' => $start,
                        'end_time' => $end,
                    ]));
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** Who can arrange a substitute; the school admins when nobody can. */
    private function recipients(): Collection
    {
        $users = $this->notifications->usersWithPermission(Permissions::TEACHER_SUBSTITUTES_CREATE);

        return $users->isNotEmpty() ? $users : $this->notifications->usersWithRole(Role::SCHOOL_ADMIN);
    }

    /** A Substitute row for this teacher (or a not-held row for the class) already covers the session. */
    private function handled(Collection $subs, int $teacherId, string $start, string $end): bool
    {
        return $subs->contains(fn (TeacherSubstitution $s) => ((int) $s->absent_staff_id === $teacherId || $s->substitute_staff_id === null)
            && ($s->start_time === null || (substr((string) $s->start_time, 0, 5) < $end && $start < substr((string) $s->end_time, 0, 5))));
    }
}
