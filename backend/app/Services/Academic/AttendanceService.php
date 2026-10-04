<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attendance is taken as a batch — a teacher marks a whole class roster for
 * one date in a single save — so this is the one place records are written,
 * and it fires a single summarizing audit entry per batch rather than one
 * per student; see AttendanceRecord's docblock.
 */
final class AttendanceService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MakeUpClassRequestService $makeUpClassRequests,
    ) {}

    /**
     * The roster for a class on a given date: every active enrollment that
     * was in this class ON THAT DATE (Enrollment::placementOn()) — so taking
     * attendance for an earlier day still lists a student who has since moved
     * to another class, and doesn't list one who only joined afterwards —
     * each paired with its existing attendance record for that date if one
     * was already taken (null otherwise — nothing is created just by
     * viewing). The `table` shown is the seat they had on that date.
     *
     * @return Collection<int, Enrollment>
     */
    public function roster(SchoolClass $class, string $date): Collection
    {
        $enrollments = $this->enrollmentsInClassOn($class, $date, Enrollment::query()->active())
            ->load(['student', 'attendanceRecords' => fn ($query) => $query->onDate($date)]);

        $tables = ClassroomTable::query()
            ->whereIn('id', $enrollments->map(fn (Enrollment $enrollment) => $enrollment->placementOn($date)['table_id'])->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        foreach ($enrollments as $enrollment) {
            $enrollment->setRelation('table', $tables->get($enrollment->placementOn($date)['table_id']));
        }

        return $enrollments
            ->sortBy(fn (Enrollment $enrollment) => [
                $enrollment->table === null ? 1 : 0,
                $enrollment->table->sort_order ?? 0,
                $enrollment->table->name ?? '',
            ])
            ->values();
    }

    /**
     * Enrollments (from $query) that were in $class on $date: anyone in it
     * now, plus anyone who has moved out of it since — then narrowed by each
     * one's actual class on that date.
     *
     * @param  Builder<Enrollment>  $query
     * @return Collection<int, Enrollment>
     */
    private function enrollmentsInClassOn(SchoolClass $class, string $date, Builder $query): Collection
    {
        return $query
            ->where(fn (Builder $query) => $query
                ->where('class_id', $class->getKey())
                ->orWhereHas('transferHistories', fn (Builder $history) => $history
                    ->where('from_class_id', $class->getKey())
                    ->whereDate('created_at', '>', $date)))
            ->with('transferHistories')
            ->get()
            ->filter(fn (Enrollment $enrollment) => (int) $enrollment->placementOn($date)['class_id'] === (int) $class->getKey())
            ->values();
    }

    /**
     * @param  list<array{enrollment_id:int, status:string, late_minutes?:int|null, remarks?:string|null}>  $entries
     * @return Collection<int, AttendanceRecord>
     */
    public function recordForClass(SchoolClass $class, string $date, array $entries, User $actor): Collection
    {
        return DB::transaction(function () use ($class, $date, $entries, $actor) {
            $enrollmentIds = collect($entries)->pluck('enrollment_id')->all();

            // In this class on THIS date — attendance for an earlier day is
            // still taken in the class the student was in back then.
            $enrollments = $this->enrollmentsInClassOn($class, $date, Enrollment::query()->whereIn('id', $enrollmentIds))
                ->keyBy('id');

            if ($enrollments->count() !== count(array_unique($enrollmentIds))) {
                throw ValidationException::withMessages(['entries' => 'One or more students are not enrolled in this class.']);
            }

            $records = new Collection;
            $counts = [];

            foreach ($entries as $entry) {
                $enrollment = $enrollments[$entry['enrollment_id']];

                $record = AttendanceRecord::query()->updateOrCreate(
                    ['enrollment_id' => $enrollment->id, 'date' => $date],
                    [
                        'class_id' => $class->getKey(),
                        'student_id' => $enrollment->student_id,
                        'status' => $entry['status'],
                        // Cleared whenever the status isn't LATE, so switching a student off
                        // Late doesn't leave a stale minutes value behind.
                        'late_minutes' => $entry['status'] === AttendanceStatus::LATE
                            ? ($entry['late_minutes'] ?? null)
                            : null,
                        'remarks' => $entry['remarks'] ?? null,
                        'recorded_by' => $actor->getKey(),
                        'recorded_at' => now(),
                    ],
                );

                $records->push($record);
                $counts[$entry['status']] = ($counts[$entry['status']] ?? 0) + 1;
            }

            $summary = collect($counts)->map(fn ($count, $status) => "{$count} ".Str::lower($status))->implode(', ');

            $this->audit->log(
                AuditAction::ATTENDANCE_RECORDED,
                'Attendance',
                $class,
                new: ['date' => $date, 'counts' => $counts],
                description: "Recorded attendance for {$class->name} on {$date}: {$summary}",
                actor: $actor,
            );

            return $records;
        });
    }

    /**
     * One row per enrollment, for one class or (classId null) every class.
     * By default only Studying enrollments — same scope as {@see roster()} —
     * not every enrollment ever made: a class that has run for years
     * accumulates hundreds of dropped/completed enrollments, which would
     * otherwise swamp the summary with all-zero rows. `$statuses` widens or
     * changes that (the Attendance Summary's status filter); an empty array
     * means every status. LATE is folded into "present" days/hours alongside
     * a separate total of late minutes. Hours per day come from whichever
     * {@see ClassSchedule} row of the class the record was TAKEN in (the
     * record's own class_id) matches that date's weekday — not the
     * enrollment's current class, which changes when a student moves from,
     * say, a Mon–Fri 1-hour class to a Sat–Sun 2-hour one; their earlier
     * days must keep counting 1 hour each. Not a single fixed duration — see
     * ClassSchedule's docblock for why a class can have a different
     * duration on different days; a day the class doesn't normally meet
     * counts its usual session length (SchoolClass::minutesOn()). `make_up_hours` is approved make-up class
     * time inside the same range (see MakeUpClassRequestService). A null
     * date bound means "no limit" — the summary's "all days".
     *
     * @param  list<string>|null  $statuses  null = Studying only; [] = all
     * @return list<array{
     *     enrollment_id:int, status:string, student:array, school_class:array|null, course_package:array|null,
     *     present_days:int, present_hours:float,
     *     permission_days:int, permission_hours:float,
     *     absent_days:int, absent_hours:float,
     *     late_minutes:int, make_up_hours:float,
     * }>
     */
    public function summarize(?int $classId, ?string $dateFrom, ?string $dateTo, ?int $studentId = null, ?array $statuses = null): array
    {
        $enrollments = Enrollment::query()
            ->with(['student', 'coursePackage', 'schoolClass'])
            ->when($classId !== null, fn ($query) => $query->where('class_id', $classId))
            ->when($statuses === null, fn ($query) => $query->active())
            ->when($statuses !== null && $statuses !== [], fn ($query) => $query->whereIn('status', $statuses))
            ->when($studentId, fn ($query) => $query->where('student_id', $studentId))
            ->get()
            ->sortBy(fn (Enrollment $enrollment) => [$enrollment->schoolClass?->name ?? '', $enrollment->student?->fullName() ?? ''])
            ->values();

        $recordsByEnrollment = AttendanceRecord::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->when($dateFrom !== null, fn ($query) => $query->whereDate('date', '>=', $dateFrom))
            ->when($dateTo !== null, fn ($query) => $query->whereDate('date', '<=', $dateTo))
            ->get()
            ->groupBy('enrollment_id');

        $makeUpHours = $this->makeUpClassRequests->approvedHoursByEnrollment($enrollments->pluck('id')->all(), $dateFrom, $dateTo);

        // Every class any of these records was taken in (current or past
        // class alike), with its schedule — see SchoolClass::minutesOn().
        $classesById = SchoolClass::query()
            ->with('schedules')
            ->whereIn('id', $recordsByEnrollment->flatten()->pluck('class_id')->filter()->unique()->values())
            ->get()
            ->keyBy('id');

        return $enrollments->map(function (Enrollment $enrollment) use ($recordsByEnrollment, $makeUpHours, $classesById) {
            $class = $enrollment->schoolClass;

            $presentDays = $permissionDays = $absentDays = 0;
            $presentMinutes = $permissionMinutes = $absentMinutes = $lateMinutes = 0;

            foreach ($recordsByEnrollment->get($enrollment->id, collect()) as $record) {
                $minutes = $classesById->get($record->class_id)?->minutesOn($record->date->dayOfWeekIso) ?? 0;

                if ($record->status === AttendanceStatus::EXCUSED) {
                    $permissionDays++;
                    $permissionMinutes += $minutes;
                } elseif ($record->status === AttendanceStatus::ABSENT) {
                    $absentDays++;
                    $absentMinutes += $minutes;
                } else {
                    // PRESENT and LATE both count as attended.
                    $presentDays++;
                    $presentMinutes += $minutes;

                    if ($record->status === AttendanceStatus::LATE) {
                        $lateMinutes += $record->late_minutes ?? 0;
                    }
                }
            }

            return [
                'enrollment_id' => $enrollment->id,
                'status' => $enrollment->status,
                'student' => [
                    'id' => $enrollment->student->id,
                    'student_code' => $enrollment->student->student_code,
                    'name' => $enrollment->student->fullName(),
                ],
                'school_class' => $class ? ['id' => $class->id, 'name' => $class->name] : null,
                'course_package' => $enrollment->coursePackage ? [
                    'id' => $enrollment->coursePackage->id,
                    'name' => $enrollment->coursePackage->name,
                ] : null,
                'present_days' => $presentDays,
                'present_hours' => round($presentMinutes / 60, 1),
                'permission_days' => $permissionDays,
                'permission_hours' => round($permissionMinutes / 60, 1),
                'absent_days' => $absentDays,
                'absent_hours' => round($absentMinutes / 60, 1),
                'late_minutes' => $lateMinutes,
                'make_up_hours' => round($makeUpHours[$enrollment->id] ?? 0, 1),
            ];
        })->values()->all();
    }
}
