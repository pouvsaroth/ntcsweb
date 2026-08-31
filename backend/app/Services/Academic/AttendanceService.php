<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Collection;
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
     * @param  list<array{enrollment_id:int, status:string, remarks?:string|null}>  $entries
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
}
