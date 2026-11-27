<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ClassSchedule;
use App\Models\LeaveRequest;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\TeacherSubstitution;
use App\Support\Academic\SchoolDaysOff;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * HRM > Teachers > Class sessions (docs/hrm/teachers.md): every scheduled
 * session of every class in a date range, and what happened to it for each
 * of its teachers. Worked out, nothing stored:
 *
 * - covered:   the teacher was away and a substitute took the session
 *              (Teachers > Substitute). The substitute gets a `covering` row.
 * - not_held:  a Substitute row with no substitute covers the session.
 * - taught:    student attendance was saved for the class that day. Late when
 *              the teacher's check-in (Attendance & Time) is after the start.
 * - missed:    no attendance, and the teacher was on approved leave or did not
 *              check in that day (`why`: leave / no_check_in / no_teacher).
 * - no_record: no attendance, but the teacher checked in — maybe taught and
 *              forgot to take attendance.
 *
 * Skipped: holidays and School Calendar closing days, days outside the
 * class's start/end dates, cancelled classes, days with no students in the
 * class, and sessions that have not ended yet.
 *
 * Student attendance is per class per day, so a class with two sessions on a
 * day counts both as taught when attendance was saved once.
 */
final class ClassSessionReport
{
    public const TAUGHT = 'taught';

    public const COVERED = 'covered';

    public const NOT_HELD = 'not_held';

    public const MISSED = 'missed';

    public const NO_RECORD = 'no_record';

    public const COVERING = 'covering';

    public const STATUSES = [self::TAUGHT, self::COVERED, self::NOT_HELD, self::MISSED, self::NO_RECORD, self::COVERING];

    /** Longest range one report covers. */
    public const MAX_DAYS = 93;

    public function __construct(private readonly AttendanceService $attendance) {}

    /**
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, summary: list<array<string, mixed>>}
     */
    public function build(string $from, string $to, ?int $staffId = null, ?int $classId = null, ?string $status = null): array
    {
        $now = CarbonImmutable::now(date_default_timezone_get());
        $today = $now->toDateString();
        $nowHm = $now->format('H:i');
        $from = CarbonImmutable::parse($from)->toDateString();
        $to = min(CarbonImmutable::parse($to)->toDateString(), $today);

        if ($from > $to) {
            return ['from' => $from, 'to' => $to, 'rows' => [], 'summary' => []];
        }

        $classes = SchoolClass::query()
            ->where('status', '!=', SchoolClass::STATUS_CANCELLED)
            ->when($classId !== null, fn ($q) => $q->whereKey($classId))
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $to))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from))
            ->whereHas('schedules')
            ->with(['schedules.book:id,title', 'teachers:id'])
            ->orderBy('name')
            ->get();

        $subs = TeacherSubstitution::query()
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->get()
            ->groupBy(fn (TeacherSubstitution $s) => $s->class_id.'|'.$s->date->toDateString());

        // Student attendance saved, per class and day.
        $taken = DB::connection('tenant')->table('attendance_records')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->when($classId !== null, fn ($q) => $q->where('class_id', $classId))
            ->groupBy('class_id', 'date')
            ->selectRaw('class_id, date, min(recorded_at) as first_at')
            ->get()
            ->keyBy(fn ($r) => $r->class_id.'|'.substr((string) $r->date, 0, 10));

        $staffIds = $classes->flatMap(fn (SchoolClass $c) => $c->teachers->modelKeys())
            ->merge($classes->flatMap(fn (SchoolClass $c) => $c->schedules->pluck('staff_id')))
            ->merge($subs->flatten()->pluck('absent_staff_id'))
            ->merge($subs->flatten()->pluck('substitute_staff_id'))
            ->filter()->unique()->values()->all();
        $staff = Staff::withTrashed()->whereIn('id', $staffIds)->get()->keyBy('id');

        $checkIns = StaffAttendance::query()->whereIn('staff_id', $staffIds)
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)
            ->whereNotNull('check_in_at')
            ->get(['staff_id', 'date', 'check_in_at'])
            ->mapWithKeys(fn (StaffAttendance $a) => [$a->staff_id.'|'.$a->date->toDateString() => $a->check_in_at->setTimezone(date_default_timezone_get())->format('H:i')]);

        $leaves = LeaveRequest::query()->whereIn('staff_id', $staffIds)->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereDate('from_date', '<=', $to)->whereDate('to_date', '>=', $from)
            ->get(['staff_id', 'from_date', 'to_date'])
            ->groupBy('staff_id');

        $daysOff = SchoolDaysOff::between($from, $to);
        $hasStudents = [];
        $rows = [];

        for ($day = CarbonImmutable::parse($from); $day->toDateString() <= $to; $day = $day->addDay()) {
            $ymd = $day->toDateString();
            if (SchoolDaysOff::covers($daysOff, $ymd)) {
                continue;
            }

            foreach ($classes as $class) {
                if (($class->start_date !== null && $class->start_date->toDateString() > $ymd) || ($class->end_date !== null && $class->end_date->toDateString() < $ymd)) {
                    continue;
                }

                $sessions = $class->schedules->filter(fn (ClassSchedule $s) => (int) $s->day_of_week === $day->dayOfWeekIso)->sortBy('start_time');
                $daySubs = $subs->get($class->id.'|'.$ymd, collect());
                $attendance = $taken->get($class->id.'|'.$ymd);

                foreach ($sessions as $session) {
                    $start = substr((string) $session->start_time, 0, 5);
                    $end = substr((string) $session->end_time, 0, 5);
                    if ($ymd === $today && $end > $nowHm) {
                        continue;
                    }

                    $base = [
                        'date' => $ymd,
                        'start_time' => $start,
                        'end_time' => $end,
                        'minutes' => $this->minutes($start, $end),
                        'class_id' => $class->id,
                        'class' => $class->name,
                        'subject' => $session->book?->title,
                        'attendance_taken' => $attendance !== null,
                    ];

                    $notHeld = $daySubs->contains(fn (TeacherSubstitution $s) => $s->substitute_staff_id === null && $this->contains($s, $start, $end));
                    $teacherIds = $session->staff_id !== null ? [(int) $session->staff_id] : $class->teachers->modelKeys();
                    $needsStudents = false;

                    foreach ($teacherIds === [] ? [null] : $teacherIds as $teacherId) {
                        $own = $teacherId === null ? null : $daySubs->first(fn (TeacherSubstitution $s) => (int) $s->absent_staff_id === $teacherId && $s->substitute_staff_id !== null && $this->overlaps($s, $start, $end));
                        $checkIn = $teacherId === null ? null : $checkIns->get($teacherId.'|'.$ymd);
                        $row = $base + [
                            'staff_id' => $teacherId,
                            'teacher' => $teacherId === null ? null : $staff->get($teacherId)?->fullName(),
                            'status' => null,
                            'why' => null,
                            'other_teacher' => null,
                            'check_in' => $checkIn,
                            'late_minutes' => 0,
                        ];

                        if ($own !== null) {
                            $row['status'] = self::COVERED;
                            $row['other_teacher'] = $staff->get($own->substitute_staff_id)?->fullName();
                        } elseif ($notHeld) {
                            $row['status'] = self::NOT_HELD;
                        } elseif ($attendance !== null) {
                            $row['status'] = self::TAUGHT;
                            $row['late_minutes'] = $checkIn !== null && $checkIn > $start ? $this->minutes($start, $checkIn) : 0;
                        } else {
                            $needsStudents = true;
                            $onLeave = $teacherId !== null && ($leaves->get($teacherId) ?? collect())->contains(fn (LeaveRequest $l) => $l->from_date->toDateString() <= $ymd && $l->to_date->toDateString() >= $ymd);
                            [$row['status'], $row['why']] = match (true) {
                                $teacherId === null => [self::MISSED, 'no_teacher'],
                                $onLeave => [self::MISSED, 'leave'],
                                $checkIn === null => [self::MISSED, 'no_check_in'],
                                default => [self::NO_RECORD, null],
                            };
                        }
                        $rows[] = $row;
                    }

                    // Nobody to teach that day: no session to count.
                    if ($needsStudents && ! ($hasStudents[$class->id.'|'.$ymd] ??= $this->attendance->hasStudentsOn($class, $ymd))) {
                        $rows = array_values(array_filter($rows, fn (array $r) => ! ($r['class_id'] === $class->id && $r['date'] === $ymd && $r['start_time'] === $start)));

                        continue;
                    }

                    foreach ($daySubs as $sub) {
                        if ($sub->substitute_staff_id !== null && $this->overlaps($sub, $start, $end)) {
                            $rows[] = $base + [
                                'staff_id' => (int) $sub->substitute_staff_id,
                                'teacher' => $staff->get($sub->substitute_staff_id)?->fullName(),
                                'status' => self::COVERING,
                                'why' => null,
                                'other_teacher' => $staff->get($sub->absent_staff_id)?->fullName(),
                                'check_in' => $checkIns->get($sub->substitute_staff_id.'|'.$ymd),
                                'late_minutes' => 0,
                            ];
                        }
                    }
                }
            }
        }

        if ($staffId !== null) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['staff_id'] === $staffId));
        }

        return [
            'from' => $from,
            'to' => $to,
            'summary' => $this->summary($rows),
            'rows' => $status === null ? $rows : array_values(array_filter($rows, fn (array $r) => $r['status'] === $status)),
        ];
    }

    /**
     * Per teacher: their own sessions by outcome, sessions they covered for
     * others, and minutes taught (own taught + covering).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function summary(array $rows): array
    {
        return collect($rows)->filter(fn (array $r) => $r['staff_id'] !== null)->groupBy('staff_id')
            ->map(function (Collection $mine, $id) {
                $own = $mine->where('status', '!=', self::COVERING);
                $taught = $own->where('status', self::TAUGHT);
                $planned = $own->count();

                return [
                    'staff_id' => (int) $id,
                    'teacher' => $mine->first()['teacher'],
                    'planned' => $planned,
                    'taught' => $taught->count(),
                    'late' => $taught->where('late_minutes', '>', 0)->count(),
                    'late_minutes' => $taught->sum('late_minutes'),
                    'covered' => $own->where('status', self::COVERED)->count(),
                    'not_held' => $own->where('status', self::NOT_HELD)->count(),
                    'missed' => $own->where('status', self::MISSED)->count(),
                    'no_record' => $own->where('status', self::NO_RECORD)->count(),
                    'covering' => $mine->where('status', self::COVERING)->count(),
                    'taught_minutes' => $taught->sum('minutes') + $mine->where('status', self::COVERING)->sum('minutes'),
                    'taught_percent' => $planned > 0 ? (int) round($taught->count() * 100 / $planned) : null,
                ];
            })
            ->sortBy('teacher', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    private function minutes(string $from, string $to): int
    {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));

        return max(0, ($th * 60 + $tm) - ($fh * 60 + $fm));
    }

    /** No time on the row = the whole day. */
    private function overlaps(TeacherSubstitution $s, string $start, string $end): bool
    {
        return $s->start_time === null || (substr((string) $s->start_time, 0, 5) < $end && $start < substr((string) $s->end_time, 0, 5));
    }

    /** The row's time holds the whole session — same rule as ClassNotHeld. */
    private function contains(TeacherSubstitution $s, string $start, string $end): bool
    {
        return $s->start_time === null || (substr((string) $s->start_time, 0, 5) <= $start && substr((string) $s->end_time, 0, 5) >= $end);
    }
}
