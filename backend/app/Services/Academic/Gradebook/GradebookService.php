<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AcademicTerm;
use App\Models\AssessmentScheme;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\GradeCorrection;
use App\Models\GradeEntry;
use App\Models\GradeEntryHistory;
use App\Models\PromotionRule;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubjectResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Grade Entry, Grade Validation and Score Calculation (docs/academics/gradebook.md, 7b).
 *
 * A result sheet is one class + subject + term. It is created on the first
 * save, under the class's grade's assessment setup at that moment (the
 * grade's own, else the default), and keeps that setup.
 */
final class GradebookService
{
    /** Enrollments that take part in a class: still in it. */
    public const IN_CLASS = [
        Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_ACTIVE, Enrollment::STATUS_EXAM_READY, Enrollment::STATUS_COMPLETED,
    ];

    public function __construct(
        private readonly GradebookAccess $access,
        private readonly Calculator $calculator,
    ) {}

    /**
     * The class's students, ordered by name.
     *
     * @return Collection<int, Student>
     */
    public function roster(SchoolClass $class): Collection
    {
        $ids = Enrollment::query()->where('class_id', $class->getKey())->whereIn('status', self::IN_CLASS)->pluck('student_id')->unique();

        return Student::query()->whereIn('id', $ids->all())->orderBy('first_name')->orderBy('last_name')->get(['id', 'student_code', 'first_name', 'last_name']);
    }

    public function sheetFor(SchoolClass $class, int $bookId, AcademicTerm $term): ?ResultSheet
    {
        return ResultSheet::query()->where('class_id', $class->getKey())->where('book_id', $bookId)->where('academic_term_id', $term->getKey())->first();
    }

    /** The setup a sheet uses: its own, else the class's grade's (or the default). */
    public function schemeFor(SchoolClass $class, ?ResultSheet $sheet): ?AssessmentScheme
    {
        $scheme = $sheet?->assessment_scheme_id ? AssessmentScheme::query()->find($sheet->assessment_scheme_id) : AssessmentScheme::for($class->grade_level_id);

        return $scheme?->load(['components', 'bands', 'criteria', 'levels']);
    }

    public function ensureTermFitsClass(SchoolClass $class, AcademicTerm $term): void
    {
        if ($class->academic_year_id !== null && (int) $class->academic_year_id !== (int) $term->academic_year_id) {
            throw ValidationException::withMessages(['academic_term_id' => __('This term is not in the class’s academic year.')]);
        }
    }

    /** Everything the Grade Entry screen shows for one class + subject + term. @return array<string, mixed> */
    public function sheet(User $user, SchoolClass $class, Book $book, AcademicTerm $term): array
    {
        $sheet = $this->sheetFor($class, $book->getKey(), $term);
        $scheme = $this->schemeFor($class, $sheet);
        $students = $this->roster($class);
        $entries = $sheet ? $sheet->entries()->get()->groupBy('student_id') : collect();
        $results = $sheet ? $sheet->results()->get()->keyBy('student_id') : collect();

        $columns = [];
        $criteria = collect();
        if ($scheme?->mode === AssessmentScheme::MODE_SCORE) {
            foreach ($scheme->components as $c) {
                for ($occ = 1; $occ <= $c->occurrences; $occ++) {
                    $columns[] = ['key' => "{$c->id}-{$occ}", 'component_id' => $c->id, 'occurrence' => $occ, 'occurrences' => $c->occurrences,
                        'name' => $c->name, 'name_km' => $c->name_km, 'max_score' => (float) $c->max_score, 'weight' => (float) $c->weight];
                }
            }
        } elseif ($scheme?->mode === AssessmentScheme::MODE_COMPETENCY) {
            $criteria = $scheme->criteria->filter(fn ($c) => $c->book_id === null || (int) $c->book_id === $book->getKey())->values();
            foreach ($criteria as $c) {
                $columns[] = ['key' => "k{$c->id}", 'criterion_id' => $c->id, 'name' => $c->name, 'name_km' => $c->name_km];
            }
        }

        $teaches = $this->access->canEnter($user, $class, $book->getKey());
        $canEnter = $teaches && ($sheet === null || $sheet->isEditable());
        $pending = $sheet ? GradeCorrection::query()->where('result_sheet_id', $sheet->id)->where('status', GradeCorrection::STATUS_PENDING)->get()
            ->map(fn (GradeCorrection $c) => $c->student_id.'|'.($c->competency_criterion_id ? "k{$c->competency_criterion_id}" : "{$c->assessment_component_id}-{$c->occurrence}"))->values() : collect();

        return [
            'class' => ['id' => $class->id, 'name' => $class->name, 'grade_level_id' => $class->grade_level_id],
            'subject' => ['id' => $book->id, 'title' => $book->title],
            'term' => ['id' => $term->id, 'name' => $term->name],
            'sheet' => $sheet ? ['id' => $sheet->id, 'status' => $sheet->status, 'decision_note' => $sheet->decision_note] : null,
            'can_enter' => $canEnter,
            'can_submit' => $teaches && $sheet !== null && $sheet->isEditable(),
            'can_request_correction' => $teaches && $sheet?->status === ResultSheet::STATUS_APPROVED,
            'pending_corrections' => $pending,
            'history_count' => $sheet ? GradeEntryHistory::query()->where('result_sheet_id', $sheet->id)->count() : 0,
            'pass_mark' => (float) (PromotionRule::for($class->grade_level_id)?->pass_mark ?? 50),
            'scheme' => $scheme ? [
                'id' => $scheme->id, 'name' => $scheme->name, 'mode' => $scheme->mode, 'max_score' => (float) $scheme->max_score,
                'decimals' => $scheme->decimals, 'absent_counts_as_zero' => $scheme->absent_counts_as_zero,
                'bands' => $scheme->bands->map(fn ($b) => ['letter' => $b->letter, 'label' => $b->label, 'label_km' => $b->label_km, 'min_percent' => (float) $b->min_percent])->values(),
                'levels' => $scheme->levels->map(fn ($l) => ['id' => $l->id, 'code' => $l->code, 'label' => $l->label, 'label_km' => $l->label_km, 'points' => (float) $l->points, 'is_passing' => $l->is_passing])->values(),
            ] : null,
            'columns' => $columns,
            'students' => $students->map(function (Student $s) use ($entries, $results) {
                $cells = [];
                foreach ($entries->get($s->id, collect()) as $e) {
                    $key = $e->competency_criterion_id ? "k{$e->competency_criterion_id}" : "{$e->assessment_component_id}-{$e->occurrence}";
                    $cells[$key] = ['score' => $e->score === null ? null : (float) $e->score, 'absent' => $e->absent, 'level_id' => $e->competency_level_id, 'remark' => $e->remark];
                }
                $r = $results->get($s->id);

                return [
                    'id' => $s->id, 'code' => $s->student_code, 'name' => $s->fullName(),
                    'cells' => (object) $cells,
                    'result' => $r ? ['percent' => $r->percent === null ? null : (float) $r->percent, 'score' => $r->score === null ? null : (float) $r->score,
                        'band' => $r->band, 'passed' => $r->passed, 'rank' => $r->rank, 'complete' => $r->complete] : null,
                ];
            })->values(),
        ];
    }

    /**
     * Save some cells (a whole class or one student). An empty cell (no score,
     * not absent, no remark / no level) removes the entry. Then recalculate.
     *
     * @param  list<array<string, mixed>>  $cells
     */
    public function save(User $user, SchoolClass $class, Book $book, AcademicTerm $term, array $cells): ResultSheet
    {
        $this->ensureTermFitsClass($class, $term);
        $sheet = $this->sheetFor($class, $book->getKey(), $term);
        if ($sheet !== null && ! $sheet->isEditable()) {
            throw ValidationException::withMessages(['sheet' => __('These results were submitted for approval and are locked. Ask for a grade correction to change them.')]);
        }
        $scheme = $this->schemeFor($class, $sheet);
        if ($scheme === null) {
            throw ValidationException::withMessages(['scheme' => __('This grade has no assessment setup yet (Examination → Assessment Setup).')]);
        }

        $roster = $this->roster($class)->pluck('id');
        $components = $scheme->components->keyBy('id');
        $criteria = $scheme->criteria->filter(fn ($c) => $c->book_id === null || (int) $c->book_id === $book->getKey())->keyBy('id');
        $levels = $scheme->levels->keyBy('id');

        // Check every cell first; nothing is saved when one is wrong.
        $errors = [];
        foreach ($cells as $i => $cell) {
            $studentId = (int) ($cell['student_id'] ?? 0);
            if (! $roster->contains($studentId)) {
                $errors["cells.{$i}.student_id"] = __('This student is not in the class.');

                continue;
            }
            if ($scheme->mode === AssessmentScheme::MODE_SCORE) {
                $component = $components->get((int) ($cell['component_id'] ?? 0));
                $occ = (int) ($cell['occurrence'] ?? 1);
                if ($component === null || $occ < 1 || $occ > $component->occurrences) {
                    $errors["cells.{$i}.component_id"] = __('This assessment is not in the setup.');

                    continue;
                }
                $score = $cell['score'] ?? null;
                if ($score !== null && $score !== '' && (! is_numeric($score) || (float) $score < 0 || (float) $score > (float) $component->max_score)) {
                    $errors["cells.{$i}.score"] = __('Enter a score from 0 to :max.', ['max' => (float) $component->max_score]);
                }
            } else {
                if (! $criteria->has((int) ($cell['criterion_id'] ?? 0))) {
                    $errors["cells.{$i}.criterion_id"] = __('This criterion is not in the setup.');
                } elseif (($cell['level_id'] ?? null) !== null && ! $levels->has((int) $cell['level_id'])) {
                    $errors["cells.{$i}.level_id"] = __('This level is not in the setup.');
                }
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::connection('tenant')->transaction(function () use ($user, $class, $book, $term, $sheet, $scheme, $cells, $roster) {
            $sheet ??= ResultSheet::query()->create([
                'class_id' => $class->getKey(), 'book_id' => $book->getKey(), 'academic_term_id' => $term->getKey(),
                'assessment_scheme_id' => $scheme->getKey(), 'status' => ResultSheet::STATUS_DRAFT,
            ]);

            foreach ($cells as $cell) {
                $remark = trim((string) ($cell['remark'] ?? '')) ?: null;
                if ($scheme->mode === AssessmentScheme::MODE_SCORE) {
                    $keys = ['result_sheet_id' => $sheet->id, 'student_id' => (int) $cell['student_id'],
                        'assessment_component_id' => (int) $cell['component_id'], 'occurrence' => (int) ($cell['occurrence'] ?? 1)];
                    $score = ($cell['score'] ?? null) === '' ? null : ($cell['score'] ?? null);
                    $absent = (bool) ($cell['absent'] ?? false);
                    if ($absent) {
                        $score = null;
                    }
                    $values = ['score' => $score, 'absent' => $absent, 'remark' => $remark, 'updated_by' => $user->getKey()];
                    $empty = $score === null && ! $absent && $remark === null;
                } else {
                    $keys = ['result_sheet_id' => $sheet->id, 'student_id' => (int) $cell['student_id'], 'competency_criterion_id' => (int) $cell['criterion_id']];
                    $values = ['competency_level_id' => $cell['level_id'] ?? null, 'remark' => $remark, 'updated_by' => $user->getKey()];
                    $empty = ($cell['level_id'] ?? null) === null && $remark === null;
                }

                if ($empty) {
                    GradeEntry::query()->where($keys)->delete();
                } else {
                    GradeEntry::query()->updateOrCreate($keys, $values);
                }
            }

            $sheet->forceFill(['updated_by' => $user->getKey()])->touch();
            $this->calculator->recalculate($sheet->load('schoolClass'), $roster);

            return $sheet;
        });
    }

    /**
     * Grade Validation: every subject of the given classes in a term, and
     * what is still wrong or missing.
     *
     * @param  Collection<int, SchoolClass>  $classes
     * @return list<array<string, mixed>>
     */
    public function validation(Collection $classes, AcademicTerm $term): array
    {
        $out = [];
        foreach ($classes as $class) {
            if ($class->academic_year_id !== null && (int) $class->academic_year_id !== (int) $term->academic_year_id) {
                continue;
            }
            $roster = $this->roster($class);
            foreach ($this->access->subjects($class) as $book) {
                $sheet = $this->sheetFor($class, $book->id, $term);
                $scheme = $this->schemeFor($class, $sheet);
                $issues = [];
                $expected = 0;
                $entered = 0;
                $absentNoRemark = 0;
                $overMax = 0;

                if ($scheme === null) {
                    $issues[] = 'no_scheme';
                } else {
                    $entries = $sheet ? $sheet->entries()->get() : collect();
                    if ($scheme->mode === AssessmentScheme::MODE_SCORE) {
                        if (abs((float) $scheme->components->sum('weight') - 100) > 0.001) {
                            $issues[] = 'weights_not_100';
                        }
                        $max = $scheme->components->keyBy('id');
                        $expected = $roster->count() * (int) $scheme->components->sum('occurrences');
                        $entered = $entries->filter(fn ($e) => $roster->contains('id', $e->student_id) && ($e->score !== null || $e->absent))->count();
                        $absentNoRemark = $entries->filter(fn ($e) => $e->absent && $e->remark === null)->count();
                        $overMax = $entries->filter(fn ($e) => $e->score !== null && $max->has($e->assessment_component_id) && (float) $e->score > (float) $max->get($e->assessment_component_id)->max_score)->count();
                    } else {
                        $criteria = $scheme->criteria->filter(fn ($c) => $c->book_id === null || (int) $c->book_id === $book->id);
                        if ($criteria->isEmpty() || $scheme->levels->isEmpty()) {
                            $issues[] = 'setup_incomplete';
                        }
                        $expected = $roster->count() * $criteria->count();
                        $entered = $entries->filter(fn ($e) => $roster->contains('id', $e->student_id) && $e->competency_level_id !== null)->count();
                    }
                }
                if ($sheet === null) {
                    $issues[] = 'not_started';
                }
                if ($expected > $entered) {
                    $issues[] = 'missing';
                }
                if ($absentNoRemark > 0) {
                    $issues[] = 'absent_without_remark';
                }
                if ($overMax > 0) {
                    $issues[] = 'over_max';
                }
                if ($roster->isEmpty()) {
                    $issues[] = 'no_students';
                }

                $out[] = [
                    'class' => ['id' => $class->id, 'name' => $class->name],
                    'subject' => ['id' => $book->id, 'title' => $book->title],
                    'status' => $sheet?->status,
                    'students' => $roster->count(),
                    'expected' => $expected,
                    'entered' => $entered,
                    'missing' => max(0, $expected - $entered),
                    'absent_without_remark' => $absentNoRemark,
                    'over_max' => $overMax,
                    'issues' => $issues,
                    'ready' => $issues === [],
                ];
            }
        }

        return $out;
    }

    /**
     * Score Calculation: one class's term results — each subject, the
     * average of the subjects, the overall score, band and rank.
     *
     * @return array<string, mixed>
     */
    public function overview(SchoolClass $class, AcademicTerm $term): array
    {
        $roster = $this->roster($class);
        $scheme = AssessmentScheme::for($class->grade_level_id)?->load('bands');
        $subjects = $this->access->subjects($class);
        $sheets = ResultSheet::query()->where('class_id', $class->getKey())->where('academic_term_id', $term->getKey())->get()->keyBy('book_id');
        $results = SubjectResult::query()->whereIn('result_sheet_id', $sheets->pluck('id')->all() ?: [0])->get()->groupBy('student_id');
        $sheetBook = $sheets->mapWithKeys(fn ($s) => [$s->id => $s->book_id]);
        $passMark = (float) (PromotionRule::for($class->grade_level_id)?->pass_mark ?? 50);

        $rows = $roster->map(function (Student $s) use ($results, $sheetBook, $subjects, $scheme, $passMark) {
            $mine = $results->get($s->id, collect())->keyBy(fn ($r) => $sheetBook[$r->result_sheet_id] ?? 0);
            $cells = [];
            $percents = [];
            $failed = 0;
            foreach ($subjects as $book) {
                $r = $mine->get($book->id);
                $cells[$book->id] = $r ? ['percent' => $r->percent === null ? null : (float) $r->percent, 'score' => $r->score === null ? null : (float) $r->score,
                    'band' => $r->band, 'passed' => $r->passed, 'complete' => $r->complete] : null;
                if ($r && $r->complete && $r->percent !== null) {
                    $percents[] = (float) $r->percent;
                    $failed += $r->passed === false ? 1 : 0;
                }
            }
            $complete = $subjects->isNotEmpty() && count($percents) === $subjects->count();
            $average = $percents !== [] ? round(array_sum($percents) / count($percents), 2) : null;

            return [
                'id' => $s->id, 'code' => $s->student_code, 'name' => $s->fullName(), 'cells' => (object) $cells,
                'average' => $average,
                'score' => $average === null || $scheme === null ? null : round($average / 100 * (float) $scheme->max_score, $scheme->decimals),
                'band' => $complete && $average !== null && $scheme ? $this->calculator->band($scheme->bands, $average) : null,
                'passed' => $complete && $average !== null ? $average + 0.00001 >= $passMark : null,
                'failed_subjects' => $failed,
                'complete' => $complete,
                'rank' => null,
            ];
        })->values()->all();

        // Rank by average among students with every subject complete.
        $order = collect($rows)->filter(fn ($r) => $r['complete'])->sortByDesc('average');
        $rank = 0;
        $seen = 0;
        $last = null;
        foreach ($order as $i => $r) {
            $seen++;
            if ($last === null || abs($r['average'] - $last) > 0.0001) {
                $rank = $seen;
                $last = $r['average'];
            }
            $rows[$i]['rank'] = $rank;
        }

        return [
            'class' => ['id' => $class->id, 'name' => $class->name],
            'term' => ['id' => $term->id, 'name' => $term->name],
            'scheme' => $scheme ? ['name' => $scheme->name, 'mode' => $scheme->mode, 'max_score' => (float) $scheme->max_score] : null,
            'pass_mark' => $passMark,
            'subjects' => $subjects->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'status' => $sheets->get($b->id)?->status])->values(),
            'students' => $rows,
        ];
    }
}
