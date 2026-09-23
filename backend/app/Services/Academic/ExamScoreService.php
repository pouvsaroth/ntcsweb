<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The Grades tab. Only approved (or make-up, see ExamApplication::
 * scopeScoreable()) exam applications can be scored — a student with no
 * application (or a pending/rejected one) never shows up here, and record()
 * refuses one even by id-guessing. Without Permissions::EXAM_SCORES_MANAGE_ALL,
 * both reading and writing are limited to applications whose enrollment sits
 * in a class the user's own Staff record teaches (class_teachers).
 */
final class ExamScoreService
{
    public const WITH = ['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'book', 'score.recordedBy', 'retake'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return Builder<ExamApplication>
     */
    public function scoreableQuery(User $user): Builder
    {
        $query = ExamApplication::query()->scoreable();

        if ($user->hasPermission(Permissions::EXAM_SCORES_MANAGE_ALL)) {
            return $query;
        }

        $classIds = $user->staff?->classes()->pluck('classes.id')->all() ?? [];

        return $query->whereHas('enrollment', fn (Builder $enrollment) => $enrollment->whereIn('class_id', $classIds));
    }

    /**
     * @param  array{course_package_id?:int|null, class_id?:int|null, book_id?:int|null, student_id?:int|null, scored?:bool|null, search?:string|null}  $filters
     * @return Builder<ExamApplication>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $query = $this->scoreableQuery($user)->with(self::WITH);

        if (! empty($filters['course_package_id'])) {
            $query->whereHas('enrollment', fn (Builder $q) => $q->where('course_package_id', $filters['course_package_id']));
        }

        if (! empty($filters['class_id'])) {
            $query->whereHas('enrollment', fn (Builder $q) => $q->where('class_id', $filters['class_id']));
        }

        if (! empty($filters['book_id'])) {
            $query->where('book_id', $filters['book_id']);
        }

        if (! empty($filters['student_id'])) {
            $query->where('student_id', $filters['student_id']);
        }

        if (isset($filters['scored'])) {
            $filters['scored'] ? $query->has('score') : $query->doesntHave('score');
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function (Builder $q) use ($term) {
                $q->whereHas('student', fn (Builder $s) => $s
                    ->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('english_name', 'like', $term)
                    ->orWhere('student_code', 'like', $term))
                    ->orWhereHas('enrollment', fn (Builder $e) => $e->where('enrollments_code', 'like', $term));
            });
        }

        return $query;
    }

    /**
     * The Course → Class → Book pickers' data: every distinct combination
     * that actually has at least one scoreable application for this user,
     * so a picker never offers a choice that would list nobody.
     *
     * @return list<array{course_package:array{id:int,name:string}, school_class:array{id:int,name:string}, book:array{id:int,title:string}|null}>
     */
    public function options(User $user): array
    {
        return $this->scoreableQuery($user)
            ->with(['enrollment.coursePackage', 'enrollment.schoolClass', 'book'])
            ->get()
            ->filter(fn (ExamApplication $a) => $a->enrollment?->coursePackage !== null && $a->enrollment->schoolClass !== null)
            ->map(fn (ExamApplication $a) => [
                'course_package' => ['id' => $a->enrollment->coursePackage->id, 'name' => $a->enrollment->coursePackage->name],
                'school_class' => ['id' => $a->enrollment->schoolClass->id, 'name' => $a->enrollment->schoolClass->name],
                'book' => $a->book !== null ? ['id' => $a->book->id, 'title' => $a->book->title] : null,
            ])
            ->unique(fn (array $row) => $row['course_package']['id'].'-'.$row['school_class']['id'].'-'.($row['book']['id'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * A null `score` clears an existing score rather than storing one. A
     * true `make_up` (only meaningful alongside a real score, never a
     * clear) generates a fresh STATUS_MAKE_UP application for the same
     * student/enrollment/book — immediately scoreable itself, no separate
     * approval step — unless one already exists for this row (see
     * ExamApplication::retake()), in which case it's a no-op: checking the
     * box twice never creates a second retake.
     *
     * A saved score with `make_up` left unchecked means the opposite: this
     * was their one sitting and there's nothing more to retake, so the
     * enrollment moves straight to completed — the same signal
     * ExamApplicationService::markNotExam() sends for "won't be sitting
     * this exam again," just reached from the scored-and-done side instead.
     *
     * @param  list<array{exam_application_id:int, score:float|int|string|null, remark?:string|null, make_up?:bool}>  $entries
     */
    public function record(array $entries, User $actor): void
    {
        DB::connection('tenant')->transaction(function () use ($entries, $actor) {
            $ids = array_values(array_unique(array_map(fn ($e) => (int) $e['exam_application_id'], $entries)));

            $applications = $this->scoreableQuery($actor)->with('retake')->whereKey($ids)->get()->keyBy('id');

            if ($applications->count() !== count($ids)) {
                throw ValidationException::withMessages([
                    'entries' => 'One or more students have no approved exam application you can score.',
                ]);
            }

            $saved = $cleared = $retakesCreated = 0;

            foreach ($entries as $entry) {
                $applicationId = (int) $entry['exam_application_id'];

                if ($entry['score'] === null || $entry['score'] === '') {
                    $cleared += ExamScore::query()->where('exam_application_id', $applicationId)->delete();

                    continue;
                }

                ExamScore::query()->updateOrCreate(
                    ['exam_application_id' => $applicationId],
                    [
                        'score' => $entry['score'],
                        'remark' => $entry['remark'] ?? null,
                        'recorded_by' => $actor->getKey(),
                        'recorded_at' => now(),
                    ],
                );
                $saved++;

                $application = $applications[$applicationId];

                if ($entry['make_up'] ?? false) {
                    if ($application->retake === null) {
                        ExamApplication::query()->create([
                            'student_id' => $application->student_id,
                            'enrollment_id' => $application->enrollment_id,
                            'book_id' => $application->book_id,
                            'retake_of_id' => $application->id,
                            'status' => ExamApplication::STATUS_MAKE_UP,
                        ]);
                        $retakesCreated++;
                    }
                } else {
                    $application->enrollment()->update(['status' => Enrollment::STATUS_COMPLETED]);
                }
            }

            $this->audit->log(
                AuditAction::EXAM_SCORES_RECORDED,
                'Exam Scores',
                new: ['exam_application_ids' => $ids, 'saved' => $saved, 'cleared' => $cleared, 'retakes_created' => $retakesCreated],
                description: "Recorded {$saved} exam score(s)"
                    .($cleared > 0 ? ", cleared {$cleared}" : '')
                    .($retakesCreated > 0 ? ", created {$retakesCreated} make-up application(s)" : ''),
                actor: $actor,
            );
        });
    }
}
