<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AcademicTerm;
use App\Models\AssessmentScheme;
use App\Models\PromotionRule;
use App\Models\ResultSheet;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\SubjectResult;
use Illuminate\Support\Collection;

/**
 * Academic Transcript, Student Progress and the student's own results
 * (docs/academics/gradebook.md, 7d) — read from the result sheets'
 * calculated subject_results.
 *
 * A transcript lists, year by year and term by term, every subject the
 * student has a result in, the term's average, score, band and rank in the
 * class, then a year line: each subject's average over the year's terms and
 * the year average. Official uses (transcript PDF, report cards, the
 * student's own view) count approved results only.
 */
final class StudentResults
{
    public function __construct(private readonly GradebookService $gradebook) {}

    /**
     * Every class + term the student has results in, oldest first.
     *
     * @return Collection<int, array{class: SchoolClass, term: AcademicTerm}>
     */
    public function periods(Student $student, bool $approvedOnly): Collection
    {
        $sheetIds = SubjectResult::query()->where('student_id', $student->getKey())->pluck('result_sheet_id');
        $pairs = ResultSheet::query()->whereIn('id', $sheetIds->all() ?: [0])
            ->when($approvedOnly, fn ($q) => $q->where('status', ResultSheet::STATUS_APPROVED))
            ->get(['class_id', 'academic_term_id'])
            ->unique(fn ($s) => $s->class_id.'-'.$s->academic_term_id);

        $classes = SchoolClass::query()->with(['gradeLevel:id,name,code', 'academicYear:id,name,start_date'])->whereIn('id', $pairs->pluck('class_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $terms = AcademicTerm::query()->with('academicYear:id,name,start_date')->whereIn('id', $pairs->pluck('academic_term_id')->unique()->all() ?: [0])->get()->keyBy('id');

        return $pairs
            ->map(fn ($p) => ['class' => $classes->get($p->class_id), 'term' => $terms->get($p->academic_term_id)])
            ->filter(fn ($p) => $p['class'] && $p['term'])
            ->sortBy(fn ($p) => sprintf('%s|%05d|%s', $p['term']->academicYear?->start_date ?? $p['term']->academicYear?->name, $p['term']->sort_order, $p['term']->start_date))
            ->values();
    }

    /** @return array<string, mixed> */
    public function transcript(Student $student, bool $approvedOnly): array
    {
        $years = [];
        foreach ($this->periods($student, $approvedOnly) as ['class' => $class, 'term' => $term]) {
            $overview = $this->gradebook->overview($class, $term, $approvedOnly);
            $row = collect($overview['students'])->firstWhere('id', $student->getKey());
            if ($row === null) {
                continue;
            }
            $cells = (array) $row['cells'];
            $subjects = collect($overview['subjects'])
                ->filter(fn ($s) => ($cells[$s['id']] ?? null) !== null)
                ->map(fn ($s) => ['id' => $s['id'], 'title' => $s['title'], 'status' => $s['status']] + $cells[$s['id']])
                ->values();

            $yearKey = (string) $term->academic_year_id;
            $years[$yearKey] ??= [
                'academic_year' => ['id' => $term->academic_year_id, 'name' => $term->academicYear?->name],
                'class' => ['id' => $class->id, 'name' => $class->name, 'grade_level' => $class->gradeLevel?->name],
                'grade_level_id' => $class->grade_level_id,
                'terms' => [],
            ];
            $years[$yearKey]['terms'][] = [
                'term' => ['id' => $term->id, 'name' => $term->name],
                'class' => ['id' => $class->id, 'name' => $class->name],
                'scheme' => $overview['scheme'],
                'subjects' => $subjects,
                'average' => $row['average'],
                'score' => $row['score'],
                'band' => $row['band'],
                'passed' => $row['passed'],
                'rank' => $row['rank'],
                'class_size' => count($overview['students']),
                'complete' => $row['complete'],
            ];
        }

        return [
            'student' => ['id' => $student->id, 'code' => $student->student_code, 'name' => $student->fullName(), 'english_name' => $student->english_name],
            'approved_only' => $approvedOnly,
            'years' => array_values(array_map(fn ($y) => $this->withYearSummary($y), $years)),
        ];
    }

    /**
     * Student Progress: one line per subject over the terms, plus the overall
     * average.
     *
     * @return array<string, mixed>
     */
    public function progress(Student $student): array
    {
        $transcript = $this->transcript($student, approvedOnly: false);
        $points = [];
        $subjects = [];
        foreach ($transcript['years'] as $year) {
            foreach ($year['terms'] as $t) {
                $label = trim(($year['academic_year']['name'] ?? '').' · '.$t['term']['name'], ' ·');
                $point = ['label' => $label, 'average' => $t['average'], 'values' => []];
                foreach ($t['subjects'] as $s) {
                    $subjects[$s['id']] = $s['title'];
                    $point['values'][$s['id']] = $s['percent'];
                }
                $point['values'] = (object) $point['values'];
                $points[] = $point;
            }
        }

        return [
            'student' => $transcript['student'],
            'subjects' => collect($subjects)->map(fn ($title, $id) => ['id' => $id, 'title' => $title])->values(),
            'points' => $points,
        ];
    }

    /**
     * Class progress: for each term of the class's year, each subject's class
     * average and the share of students passing it.
     *
     * @return array<string, mixed>
     */
    public function classProgress(SchoolClass $class): array
    {
        $terms = AcademicTerm::query()
            ->when($class->academic_year_id, fn ($q) => $q->where('academic_year_id', $class->academic_year_id))
            ->whereIn('id', ResultSheet::query()->where('class_id', $class->id)->select('academic_term_id'))
            ->orderBy('sort_order')->orderBy('start_date')->get();

        $subjects = [];
        $points = [];
        foreach ($terms as $term) {
            $overview = $this->gradebook->overview($class, $term);
            $point = ['label' => $term->name, 'average' => null, 'pass_rate' => null, 'values' => []];
            foreach ($overview['subjects'] as $s) {
                $percents = collect($overview['students'])->map(fn ($r) => ((array) $r['cells'])[$s['id']]['percent'] ?? null)->filter(fn ($v) => $v !== null);
                if ($percents->isNotEmpty()) {
                    $subjects[$s['id']] = $s['title'];
                    $point['values'][$s['id']] = round($percents->avg(), 2);
                }
            }
            $averages = collect($overview['students'])->pluck('average')->filter(fn ($v) => $v !== null);
            $complete = collect($overview['students'])->filter(fn ($r) => $r['passed'] !== null);
            $point['average'] = $averages->isNotEmpty() ? round($averages->avg(), 2) : null;
            $point['pass_rate'] = $complete->isNotEmpty() ? round($complete->where('passed', true)->count() / $complete->count() * 100, 1) : null;
            $point['values'] = (object) $point['values'];
            $points[] = $point;
        }

        return [
            'class' => ['id' => $class->id, 'name' => $class->name],
            'subjects' => collect($subjects)->map(fn ($title, $id) => ['id' => $id, 'title' => $title])->values(),
            'points' => $points,
        ];
    }

    /** The year line: each subject's average over the terms, and the year average. */
    private function withYearSummary(array $year): array
    {
        $bySubject = [];
        foreach ($year['terms'] as $t) {
            foreach ($t['subjects'] as $s) {
                if ($s['percent'] !== null && $s['complete']) {
                    $bySubject[$s['id']]['title'] = $s['title'];
                    $bySubject[$s['id']]['values'][] = (float) $s['percent'];
                }
            }
        }
        $scheme = AssessmentScheme::for($year['grade_level_id'])?->load('bands');
        $passMark = (float) (PromotionRule::for($year['grade_level_id'])?->pass_mark ?? 50);
        $scale = fn (?float $p) => $p === null || $scheme === null ? null : round($p / 100 * (float) $scheme->max_score, $scheme->decimals);

        $subjects = collect($bySubject)->map(function ($s, $id) use ($scale) {
            $avg = round(array_sum($s['values']) / count($s['values']), 2);

            return ['id' => $id, 'title' => $s['title'], 'percent' => $avg, 'score' => $scale($avg)];
        })->values();
        $termAverages = collect($year['terms'])->pluck('average')->filter(fn ($v) => $v !== null);
        $average = $termAverages->isNotEmpty() ? round($termAverages->avg(), 2) : null;
        $complete = collect($year['terms'])->every(fn ($t) => $t['complete']);

        unset($year['grade_level_id']);

        return $year + ['year' => [
            'subjects' => $subjects,
            'average' => $average,
            'score' => $scale($average),
            'band' => $complete && $average !== null && $scheme ? app(Calculator::class)->band($scheme->bands, $average) : null,
            'passed' => $complete && $average !== null ? $average + 0.00001 >= $passMark : null,
            'complete' => $complete,
        ]];
    }
}
