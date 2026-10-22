<?php

declare(strict_types=1);

namespace App\Services\Academic\Gradebook;

use App\Models\AssessmentScheme;
use App\Models\GradeBand;
use App\Models\GradeEntry;
use App\Models\PromotionRule;
use App\Models\ResultSheet;
use App\Models\SubjectResult;
use Illuminate\Support\Collection;

/**
 * Score Calculation (docs/academics/gradebook.md). Recalculates a result
 * sheet's subject_results from its entries, under the sheet's setup.
 *
 * Score mode, per student:
 *  - each component: the average of its times (absent = 0, or left out when
 *    the setup says so) over the component's maximum;
 *  - percent = the components' weighted average (weights are normalised, so a
 *    setup whose weights do not add up to 100 still gives a 0–100 result);
 *  - score = percent on the setup's scale (e.g. out of 50), rounded to its decimals;
 *  - band = the highest band at or below the percent; passed = percent at or
 *    above the grade's pass mark (promotion rules, default 50).
 * Competency mode: percent = average points over the top level's points; the
 * band is the level nearest the average; passed = no criterion at a level
 * that does not pass.
 *
 * Band, passed and rank are only set once every entry is in (complete); the
 * percent is shown as it builds up. Rank is within the class, ties share a
 * rank (1, 2, 2, 4).
 */
final class Calculator
{
    /**
     * @param  Collection<int, int>  $studentIds  the class's students, in roster order
     */
    public function recalculate(ResultSheet $sheet, Collection $studentIds): void
    {
        $scheme = $sheet->scheme()->with(['components', 'bands', 'criteria', 'levels'])->first();
        if ($scheme === null) {
            $sheet->results()->delete();

            return;
        }

        $entries = $sheet->entries()->get()->groupBy('student_id');
        $passMark = (float) (PromotionRule::for($sheet->schoolClass?->grade_level_id)?->pass_mark ?? 50);
        $bookId = (int) $sheet->book_id;

        $rows = [];
        foreach ($studentIds as $studentId) {
            $mine = $entries->get($studentId, collect());
            $rows[$studentId] = $scheme->mode === AssessmentScheme::MODE_COMPETENCY
                ? $this->competency($scheme, $mine, $bookId)
                : $this->score($scheme, $mine, $passMark);
        }

        // Ranks among complete results, ties share a rank.
        $complete = collect($rows)->filter(fn ($r) => $r['complete'] && $r['percent'] !== null)->sortByDesc('percent');
        $rank = 0;
        $seen = 0;
        $last = null;
        foreach ($complete as $studentId => $row) {
            $seen++;
            if ($last === null || abs($row['percent'] - $last) > 0.0001) {
                $rank = $seen;
                $last = $row['percent'];
            }
            $rows[$studentId]['rank'] = $rank;
        }

        foreach ($rows as $studentId => $row) {
            SubjectResult::query()->updateOrCreate(
                ['result_sheet_id' => $sheet->getKey(), 'student_id' => $studentId],
                $row + ['rank' => $row['rank'] ?? null],
            );
        }
        $sheet->results()->whereNotIn('student_id', $studentIds->all() ?: [0])->delete();
    }

    /**
     * @param  Collection<int, GradeEntry>  $entries
     * @return array{percent: float|null, score: float|null, band: string|null, passed: bool|null, complete: bool}
     */
    public function score(AssessmentScheme $scheme, Collection $entries, float $passMark): array
    {
        $byKey = $entries->keyBy(fn (GradeEntry $e) => $e->assessment_component_id.'-'.$e->occurrence);
        $complete = $scheme->components->isNotEmpty();
        $weighted = 0.0;
        $weights = 0.0;

        foreach ($scheme->components as $component) {
            $values = [];
            for ($occ = 1; $occ <= $component->occurrences; $occ++) {
                $entry = $byKey->get($component->id.'-'.$occ);
                if ($entry === null || ($entry->score === null && ! $entry->absent)) {
                    $complete = false;

                    continue;
                }
                if ($entry->absent) {
                    if ($scheme->absent_counts_as_zero) {
                        $values[] = 0.0;
                    }

                    continue;
                }
                $values[] = (float) $entry->score;
            }
            if ($values !== [] && (float) $component->max_score > 0) {
                $weighted += (array_sum($values) / count($values)) / (float) $component->max_score * (float) $component->weight;
                $weights += (float) $component->weight;
            }
        }

        $percent = $weights > 0 ? round($weighted / $weights * 100, 2) : null;

        return [
            'percent' => $percent,
            'score' => $percent === null ? null : round($percent / 100 * (float) $scheme->max_score, $scheme->decimals),
            'band' => $complete && $percent !== null ? $this->band($scheme->bands, $percent) : null,
            'passed' => $complete && $percent !== null ? $percent + 0.00001 >= $passMark : null,
            'complete' => $complete && $percent !== null,
        ];
    }

    /**
     * @param  Collection<int, GradeEntry>  $entries
     * @return array{percent: float|null, score: float|null, band: string|null, passed: bool|null, complete: bool}
     */
    public function competency(AssessmentScheme $scheme, Collection $entries, int $bookId): array
    {
        $criteria = $scheme->criteria->filter(fn ($c) => $c->book_id === null || (int) $c->book_id === $bookId);
        $levels = $scheme->levels->keyBy('id');
        $top = (float) ($scheme->levels->max('points') ?? 0);
        $byCriterion = $entries->keyBy('competency_criterion_id');

        $points = [];
        $passed = true;
        foreach ($criteria as $criterion) {
            $level = $levels->get($byCriterion->get($criterion->id)?->competency_level_id);
            if ($level === null) {
                continue;
            }
            $points[] = (float) $level->points;
            $passed = $passed && $level->is_passing;
        }

        $complete = $criteria->isNotEmpty() && count($points) === $criteria->count() && $top > 0;
        $percent = $points !== [] && $top > 0 ? round(array_sum($points) / count($points) / $top * 100, 2) : null;
        $average = $points !== [] ? array_sum($points) / count($points) : null;
        $nearest = $average === null ? null : $scheme->levels->sortBy(fn ($l) => abs((float) $l->points - $average))->first();

        return [
            'percent' => $percent,
            'score' => $percent === null ? null : round($percent / 100 * (float) $scheme->max_score, $scheme->decimals),
            'band' => $complete ? $nearest?->code : null,
            'passed' => $complete ? $passed : null,
            'complete' => $complete,
        ];
    }

    /** @param Collection<int, GradeBand> $bands */
    public function band(Collection $bands, float $percent): ?string
    {
        return $bands->sortByDesc('min_percent')->first(fn (GradeBand $b) => $percent + 0.00001 >= (float) $b->min_percent)?->letter;
    }
}
