<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewAnswer;
use App\Models\PerformanceReviewKpi;
use App\Models\Staff;

/**
 * A performance review as each side sees it:
 *
 * - 'hr': everything.
 * - 'self' (the staff member): their own answers; the manager's ratings,
 *   comments and the scores only once the review is completed.
 * - 'manager': everything; the staff member's own answers once they've
 *   sent them.
 */
final class PerformanceReviewPresenter
{
    /** @return array<string, mixed> one row of a list */
    public static function row(PerformanceReview $review): array
    {
        return [
            'id' => $review->id,
            'cycle' => $review->cycle !== null ? [
                'id' => $review->cycle->id,
                'name' => $review->cycle->name,
                'status' => $review->cycle->status,
                'self_assessment_due' => $review->cycle->self_assessment_due?->toDateString(),
                'manager_assessment_due' => $review->cycle->manager_assessment_due?->toDateString(),
            ] : null,
            'staff' => self::staff($review->staff),
            'reviewer' => self::staff($review->reviewer),
            'status' => $review->status,
            'self_submitted_at' => $review->self_submitted_at?->toIso8601String(),
            'manager_submitted_at' => $review->manager_submitted_at?->toIso8601String(),
            'kpi_score' => $review->kpi_score,
            'goal_score' => $review->goal_score,
            'manager_score' => $review->manager_score,
            'self_score' => $review->self_score,
            'final_score' => $review->final_score,
        ];
    }

    /** @return array<string, mixed> */
    public static function detail(PerformanceReview $review, string $view): array
    {
        $completed = $review->status === PerformanceReview::STATUS_COMPLETED;
        $showManager = $view !== 'self' || $completed;
        $showSelf = $view !== 'manager' || $review->self_submitted_at !== null || $review->status !== PerformanceReview::STATUS_SELF;

        $row = self::row($review);
        if (! $showManager) {
            foreach (['kpi_score', 'goal_score', 'manager_score', 'final_score'] as $score) {
                $row[$score] = null;
            }
        }
        if (! $showSelf) {
            $row['self_score'] = null;
        }

        return [
            ...$row,
            'self_comment' => $showSelf ? $review->self_comment : null,
            'manager_comment' => $showManager ? $review->manager_comment : null,
            'manager_overall_rating' => $showManager ? $review->manager_overall_rating : null,
            'kpis' => $review->kpis()->get()->map(fn (PerformanceReviewKpi $k) => [
                'id' => $k->id,
                'name' => $k->name,
                'measurement' => $k->measurement,
                'unit' => $k->unit,
                'target' => $k->target,
                'higher_is_better' => $k->higher_is_better,
                'weight' => $k->weight,
                'actual' => $k->actual,
                'self_rating' => $showSelf ? $k->self_rating : null,
                'manager_rating' => $showManager ? $k->manager_rating : null,
                'comment' => $showManager ? $k->comment : null,
            ])->values(),
            'goals' => $review->goals()->get()->map(fn (PerformanceGoal $g) => [
                'id' => $g->id,
                'title' => $g->title,
                'description' => $g->description,
                'due_date' => $g->due_date?->toDateString(),
                'weight' => $g->weight,
                'progress' => $g->progress,
                'status' => $g->status,
                'self_rating' => $showSelf ? $g->self_rating : null,
                'manager_rating' => $showManager ? $g->manager_rating : null,
            ])->values(),
            'answers' => $review->answers()->get()->map(fn (PerformanceReviewAnswer $a) => [
                'id' => $a->id,
                'section' => $a->section,
                'question' => $a->question,
                'type' => $a->type,
                'is_required' => $a->is_required,
                'self_rating' => $showSelf ? $a->self_rating : null,
                'self_answer' => $showSelf ? $a->self_answer : null,
                'manager_rating' => $showManager ? $a->manager_rating : null,
                'manager_answer' => $showManager ? $a->manager_answer : null,
            ])->values(),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function staff(?Staff $staff): ?array
    {
        return $staff !== null ? [
            'id' => $staff->id,
            'name' => $staff->fullName(),
            'employee_code' => $staff->employee_code,
            'position' => $staff->relationLoaded('position') ? $staff->position?->name : null,
            'department' => $staff->relationLoaded('department') ? $staff->department?->name : null,
        ] : null;
    }
}
