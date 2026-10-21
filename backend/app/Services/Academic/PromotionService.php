<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\PromotionRule;
use App\Models\SchoolClass;
use App\Models\StudentPromotion;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promote students (docs/academics/school-setup.md, stage 4): checks each
 * student of a class against its grade's promotion rule, then moves the
 * chosen ones on.
 *
 * The figures, per enrollment in the class:
 *  - average: the mean of the student's latest score (0–100) in each subject
 *    (exam applications of this enrollment; of the class's academic year
 *    when the class has one);
 *  - failed subjects: subjects whose latest score is below the pass mark;
 *  - attendance: present, late and excused days over all recorded days.
 * A check whose figure is missing (no scores, no attendance taken) is not met.
 */
final class PromotionService
{
    /** Enrollments of a class that take part: still in it, not cancelled or transferred out. */
    private const IN_CLASS = [
        Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_EXAM_READY, Enrollment::STATUS_COMPLETED,
    ];

    public function __construct(private readonly EnrollmentService $enrollments) {}

    /**
     * @return array{rule: PromotionRule|null, students: list<array<string, mixed>>}
     */
    public function evaluate(SchoolClass $class): array
    {
        $rule = PromotionRule::for($class->grade_level_id);
        $passMark = $rule !== null ? (float) $rule->pass_mark : 50.0;

        $enrollments = Enrollment::query()->with('student')
            ->where('class_id', $class->getKey())->whereIn('status', self::IN_CLASS)
            ->get();
        $ids = $enrollments->modelKeys();

        $scores = $this->latestScores($ids, $class->academic_year_id);
        $attendance = $this->attendance($ids);
        $done = StudentPromotion::query()->with('toClass:id,name')->whereIn('from_enrollment_id', $ids)->get()->keyBy('from_enrollment_id');

        $students = $enrollments->map(function (Enrollment $e) use ($rule, $passMark, $scores, $attendance, $done) {
            $subjectScores = $scores->get($e->getKey(), collect());
            $average = $subjectScores->isEmpty() ? null : round((float) $subjectScores->avg(), 2);
            $failed = $subjectScores->isEmpty() ? null : $subjectScores->filter(fn ($s) => $s < $passMark)->count();
            $rate = $attendance[$e->getKey()] ?? null;

            $reasons = [];
            if ($rule !== null) {
                if ($rule->min_average !== null) {
                    if ($average === null) {
                        $reasons[] = 'no_scores';
                    } elseif ($average < (float) $rule->min_average) {
                        $reasons[] = 'average_low';
                    }
                }
                if ($rule->min_attendance !== null) {
                    if ($rate === null) {
                        $reasons[] = 'no_attendance';
                    } elseif ($rate < (float) $rule->min_attendance) {
                        $reasons[] = 'attendance_low';
                    }
                }
                if ($rule->max_failed_subjects !== null) {
                    if ($failed === null) {
                        $reasons[] = 'no_scores';
                    } elseif ($failed > $rule->max_failed_subjects) {
                        $reasons[] = 'too_many_failed';
                    }
                }
            }
            $reasons = array_values(array_unique($reasons));
            $record = $done->get($e->getKey());

            return [
                'enrollment_id' => $e->id,
                'student_id' => $e->student_id,
                'student_code' => $e->student?->student_code,
                'student_name' => $e->student?->auditDisplayName(),
                'enrollment_status' => $e->status,
                'average' => $average,
                'subjects' => $subjectScores->count(),
                'failed_subjects' => $failed,
                'attendance_rate' => $rate,
                'passes' => $reasons === [],
                'reasons' => $reasons,
                'done' => $record === null ? null : [
                    'result' => $record->result,
                    'to_class' => $record->toClass?->name,
                    'at' => $record->created_at?->toDateString(),
                ],
            ];
        })->sortBy('student_name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();

        return ['rule' => $rule, 'students' => $students];
    }

    /**
     * @param  list<array{enrollment_id: int, action: string}>  $decisions  action: promote | repeat | graduate
     * @return array{promoted: int, repeated: int, graduated: int, skipped: int}
     */
    public function apply(SchoolClass $class, ?SchoolClass $toClass, ?SchoolClass $repeatClass, array $decisions, User $actor): array
    {
        if ($toClass !== null && $toClass->is($class)) {
            throw ValidationException::withMessages(['to_class_id' => __('Choose a class other than this one.')]);
        }

        $figures = collect($this->evaluate($class)['students'])->keyBy('enrollment_id');
        $counts = ['promoted' => 0, 'repeated' => 0, 'graduated' => 0, 'skipped' => 0];

        DB::connection('tenant')->transaction(function () use ($class, $toClass, $repeatClass, $decisions, $actor, $figures, &$counts) {
            foreach ($decisions as $decision) {
                $row = $figures->get((int) $decision['enrollment_id']);
                // Not in this class, or already decided: left as it is.
                if ($row === null || $row['done'] !== null) {
                    $counts['skipped']++;

                    continue;
                }

                $target = match ($decision['action']) {
                    'promote' => $toClass,
                    'repeat' => $repeatClass,
                    default => null,
                };
                if ($decision['action'] === 'promote' && $target === null) {
                    throw ValidationException::withMessages(['to_class_id' => __('Choose the class to promote students into.')]);
                }

                $newEnrollment = null;
                if ($target !== null) {
                    $already = Enrollment::query()->where('student_id', $row['student_id'])->where('class_id', $target->getKey())
                        ->whereIn('status', self::IN_CLASS)->first();
                    $newEnrollment = $already ?? $this->enrollments->enrollForPromotion((int) $row['student_id'], $target);
                }

                /** @var Enrollment $from */
                $from = Enrollment::query()->findOrFail($row['enrollment_id']);
                if ($from->status !== Enrollment::STATUS_COMPLETED) {
                    $this->enrollments->changeStatus($from, Enrollment::STATUS_COMPLETED, null, now()->toDateString(), $actor);
                }

                $result = match ($decision['action']) {
                    'promote' => StudentPromotion::PROMOTED,
                    'repeat' => StudentPromotion::REPEATED,
                    default => StudentPromotion::GRADUATED,
                };
                StudentPromotion::query()->create([
                    'student_id' => $row['student_id'],
                    'from_enrollment_id' => $row['enrollment_id'],
                    'from_class_id' => $class->getKey(),
                    'to_class_id' => $target?->getKey(),
                    'to_enrollment_id' => $newEnrollment?->getKey(),
                    'academic_year_id' => $class->academic_year_id,
                    'result' => $result,
                    'met_rule' => $row['passes'],
                    'average' => $row['average'],
                    'attendance_rate' => $row['attendance_rate'],
                    'failed_subjects' => $row['failed_subjects'],
                    'decided_by' => $actor->getKey(),
                ]);
                $counts[$result]++;
            }
        });

        return $counts;
    }

    /**
     * Each enrollment's latest score per subject.
     *
     * @param  list<int>  $enrollmentIds
     * @return Collection<int, Collection<int, float>> enrollment id => (book id => score)
     */
    private function latestScores(array $enrollmentIds, ?int $academicYearId): Collection
    {
        if ($enrollmentIds === []) {
            return collect();
        }

        $rows = DB::connection('tenant')->table('exam_scores as s')
            ->join('exam_applications as a', 'a.id', '=', 's.exam_application_id')
            ->whereIn('a.enrollment_id', $enrollmentIds)
            ->whereNull('a.deleted_at')
            ->whereNotNull('s.score')
            ->when($academicYearId !== null, fn ($q) => $q->where(fn ($q) => $q->where('s.academic_year_id', $academicYearId)->orWhereNull('s.academic_year_id')))
            ->orderBy('s.id')
            ->get(['a.enrollment_id', 'a.book_id', 's.score']);

        $out = collect();
        foreach ($rows as $row) {
            $subjects = $out->get($row->enrollment_id, collect());
            // Ordered by id: a later score (a retake) replaces the earlier one.
            $subjects->put($row->book_id ?? 0, (float) $row->score);
            $out->put($row->enrollment_id, $subjects);
        }

        return $out;
    }

    /**
     * @param  list<int>  $enrollmentIds
     * @return array<int, float> enrollment id => attendance %
     */
    private function attendance(array $enrollmentIds): array
    {
        if ($enrollmentIds === []) {
            return [];
        }

        $rows = DB::connection('tenant')->table('attendance_records')
            ->whereIn('enrollment_id', $enrollmentIds)
            ->selectRaw('enrollment_id, count(*) as total, sum(case when status in (?, ?, ?) then 1 else 0 end) as attended', [
                AttendanceStatus::PRESENT, AttendanceStatus::LATE, AttendanceStatus::EXCUSED,
            ])
            ->groupBy('enrollment_id')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            if ((int) $row->total > 0) {
                $out[(int) $row->enrollment_id] = round(((int) $row->attended / (int) $row->total) * 100, 2);
            }
        }

        return $out;
    }
}
