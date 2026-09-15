<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
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
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * The roster for a class on a given date: every active enrollment, each
     * paired with its existing attendance record for that date if one was
     * already taken (null otherwise — nothing is created just by viewing).
     *
     * @return Collection<int, Enrollment>
     */
    public function roster(SchoolClass $class, string $date): Collection
    {
        return $class->enrollments()
            ->active()
            ->with(['student', 'attendanceRecords' => fn ($query) => $query->onDate($date)])
            ->get();
    }

    /**
     * @param  list<array{enrollment_id:int, status:string, late_minutes?:int|null, remarks?:string|null}>  $entries
     * @return Collection<int, AttendanceRecord>
     */
    public function recordForClass(SchoolClass $class, string $date, array $entries, User $actor): Collection
    {
        return DB::transaction(function () use ($class, $date, $entries, $actor) {
            $enrollmentIds = collect($entries)->pluck('enrollment_id')->all();

            $enrollments = Enrollment::query()
                ->where('class_id', $class->getKey())
                ->whereIn('id', $enrollmentIds)
                ->get()
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
                        'class_id' => $enrollment->class_id,
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
     * One row per *currently active* enrollment in the class — same scope as
     * {@see roster()} — not every enrollment ever made in it: a class that
     * has run for years accumulates hundreds of dropped/completed
     * enrollments, which would otherwise swamp the summary with all-zero
     * rows for students no longer in the class. LATE is folded into
     * "present" days/hours alongside a separate total of late minutes. Hours
     * per day come from whichever {@see ClassSchedule} row matches that
     * date's weekday, not a single fixed class duration — see
     * ClassSchedule's docblock for why a class can have a different
     * duration on different days.
     *
     * @return list<array{
     *     enrollment_id:int, student:array, course_package:array|null,
     *     present_days:int, present_hours:float,
     *     permission_days:int, permission_hours:float,
     *     absent_days:int, absent_hours:float,
     *     late_minutes:int,
     * }>
     */
    public function summarizeForClass(SchoolClass $class, string $dateFrom, string $dateTo, ?int $studentId = null): array
    {
        $minutesByWeekday = $class->schedules()->get()
            ->groupBy('day_of_week')
            ->map(fn ($rows) => $rows->sum(fn ($schedule) => abs(Carbon::parse($schedule->end_time)->diffInMinutes(Carbon::parse($schedule->start_time)))));

        $enrollments = $class->enrollments()
            ->active()
            ->with(['student', 'coursePackage'])
            ->when($studentId, fn ($query) => $query->where('student_id', $studentId))
            ->get();

        $recordsByEnrollment = AttendanceRecord::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->whereBetween('date', [$dateFrom, $dateTo])
            ->get()
            ->groupBy('enrollment_id');

        return $enrollments->map(function (Enrollment $enrollment) use ($recordsByEnrollment, $minutesByWeekday) {
            $presentDays = $permissionDays = $absentDays = 0;
            $presentMinutes = $permissionMinutes = $absentMinutes = $lateMinutes = 0;

            foreach ($recordsByEnrollment->get($enrollment->id, collect()) as $record) {
                $minutes = $minutesByWeekday[$record->date->dayOfWeekIso] ?? 0;

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
                'student' => [
                    'id' => $enrollment->student->id,
                    'student_code' => $enrollment->student->student_code,
                    'name' => $enrollment->student->fullName(),
                ],
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
            ];
        })->values()->all();
    }
}
