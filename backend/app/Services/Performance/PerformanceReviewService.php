<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Models\EvaluationFormQuestion;
use App\Models\Kpi;
use App\Models\PerformanceCycle;
use App\Models\PerformanceGoal;
use App\Models\PerformanceReview;
use App\Models\PerformanceReviewAnswer;
use App\Models\PerformanceReviewKpi;
use App\Models\PerformanceSetting;
use App\Models\Staff;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A performance review's life — see PerformanceReview:
 *
 * - launch(): a review for each chosen staff member not already in the
 *   cycle — their manager is their Reports-to; the KPIs that apply to them
 *   (everyone's, their department's, their position's) and the cycle
 *   form's questions are copied in. Staff are told it's open.
 * - saveSelf() / submitSelf(): the staff member's own ratings and answers;
 *   submitting hands it to the manager (who is told).
 * - saveManager() / submitManager(): the manager's; submitting completes it
 *   and works out the scores (the staff member is told).
 * - sendToManager() / reopen(): HR moving one on without the self part, or
 *   back to the manager after completion.
 *
 * Scores are 1–5: each part (KPIs, goals, manager's form answers) is the
 * weighted average of the manager's ratings; the final score weighs the
 * parts as PerformanceSetting says, shared out between the parts that have
 * a score. The self score (average of every self rating) is shown, not
 * counted.
 */
final class PerformanceReviewService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * @param  list<int>  $staffIds
     * @return Collection<int, PerformanceReview> the reviews created
     */
    public function launch(PerformanceCycle $cycle, array $staffIds, User $actor): Collection
    {
        if ($cycle->status === PerformanceCycle::STATUS_CLOSED) {
            throw ValidationException::withMessages(['performance_cycle_id' => 'This review cycle is closed.']);
        }

        $already = PerformanceReview::query()->where('performance_cycle_id', $cycle->id)->pluck('staff_id')->all();
        $staff = Staff::query()->whereIn('id', $staffIds)->whereNotIn('id', $already)->get();
        $kpis = Kpi::query()->where('is_active', true)->orderBy('code')->get();
        $questions = $cycle->evaluation_form_id !== null
            ? EvaluationFormQuestion::query()->where('evaluation_form_id', $cycle->evaluation_form_id)->orderBy('sort_order')->orderBy('id')->get()
            : collect();

        $created = DB::connection('tenant')->transaction(function () use ($cycle, $staff, $kpis, $questions, $actor) {
            $created = collect();
            foreach ($staff as $member) {
                $review = PerformanceReview::query()->create([
                    'performance_cycle_id' => $cycle->id,
                    'staff_id' => $member->id,
                    'reviewer_staff_id' => $member->reports_to_staff_id,
                    'created_by' => $actor->getKey(),
                ]);

                $order = 0;
                foreach ($kpis as $kpi) {
                    if (($kpi->department_id !== null && $kpi->department_id !== $member->department_id)
                        || ($kpi->position_id !== null && $kpi->position_id !== $member->position_id)) {
                        continue;
                    }
                    $review->kpis()->create([
                        'kpi_id' => $kpi->id,
                        'name' => $kpi->name,
                        'measurement' => $kpi->measurement,
                        'unit' => $kpi->unit,
                        'target' => $kpi->target,
                        'higher_is_better' => $kpi->higher_is_better,
                        'weight' => $kpi->default_weight,
                        'sort_order' => $order++,
                    ]);
                }

                foreach ($questions as $index => $question) {
                    $review->answers()->create([
                        'evaluation_form_question_id' => $question->id,
                        'section' => $question->section,
                        'question' => $question->question,
                        'type' => $question->type,
                        'is_required' => $question->is_required,
                        'sort_order' => $index,
                    ]);
                }

                $created->push($review);
            }

            if ($cycle->status === PerformanceCycle::STATUS_DRAFT && $created->isNotEmpty()) {
                $cycle->update(['status' => PerformanceCycle::STATUS_ACTIVE]);
            }

            return $created;
        });

        foreach ($created as $review) {
            $this->notifyStaff($review, NotificationType::PERFORMANCE_SELF_ASSESSMENT_OPEN, '/admin/performance/self-assessment');
        }

        return $created;
    }

    /** @param  array<string, mixed>  $data  kpis / goals / answers (id + self fields), self_comment */
    public function saveSelf(PerformanceReview $review, array $data): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_SELF], 'Your self-assessment has already been sent.');

        DB::connection('tenant')->transaction(function () use ($review, $data) {
            $this->fill($review, $data, ['kpis' => ['actual', 'self_rating'], 'goals' => ['self_rating', 'progress'], 'answers' => ['self_rating', 'self_answer']]);
            if (array_key_exists('self_comment', $data)) {
                $review->update(['self_comment' => $data['self_comment']]);
            }
        });

        return $review->fresh();
    }

    public function submitSelf(PerformanceReview $review): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_SELF], 'Your self-assessment has already been sent.');
        $this->assertAnswered($review, 'self');

        $review->update(['status' => PerformanceReview::STATUS_MANAGER, 'self_submitted_at' => now(), 'self_score' => $this->selfScore($review)]);
        $this->notifyReviewer($review);

        return $review->fresh();
    }

    /** @param  array<string, mixed>  $data  kpis / goals / answers (id + manager fields), manager_comment, manager_overall_rating */
    public function saveManager(PerformanceReview $review, array $data): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_SELF, PerformanceReview::STATUS_MANAGER], 'This review is already completed.');

        DB::connection('tenant')->transaction(function () use ($review, $data) {
            $this->fill($review, $data, ['kpis' => ['actual', 'manager_rating', 'comment'], 'goals' => ['manager_rating', 'progress'], 'answers' => ['manager_rating', 'manager_answer']]);
            $review->update(collect($data)->only(['manager_comment', 'manager_overall_rating'])->all());
        });

        return $review->fresh();
    }

    public function submitManager(PerformanceReview $review): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_MANAGER], $review->status === PerformanceReview::STATUS_SELF
            ? 'The staff member hasn\'t sent their self-assessment yet.'
            : 'This review is already completed.');
        $this->assertAnswered($review, 'manager');

        $review->update(['status' => PerformanceReview::STATUS_COMPLETED, 'manager_submitted_at' => now(), ...$this->scores($review)]);
        $this->notifyStaff($review, NotificationType::PERFORMANCE_REVIEW_COMPLETED, '/admin/performance/self-assessment');

        return $review->fresh();
    }

    /** HR: on to the manager without the staff member's own part. */
    public function sendToManager(PerformanceReview $review): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_SELF], 'This review is already with the manager.');

        $review->update(['status' => PerformanceReview::STATUS_MANAGER, 'self_score' => $this->selfScore($review)]);
        $this->notifyReviewer($review);

        return $review->fresh();
    }

    /** HR: back to the manager to change, scores cleared until it's sent again. */
    public function reopen(PerformanceReview $review): PerformanceReview
    {
        $this->assertStatus($review, [PerformanceReview::STATUS_COMPLETED], 'Only a completed review can be reopened.');

        $review->update(['status' => PerformanceReview::STATUS_MANAGER, 'manager_submitted_at' => null, 'kpi_score' => null, 'goal_score' => null, 'manager_score' => null, 'final_score' => null]);

        return $review->fresh();
    }

    /** @return array{kpi_score: ?float, goal_score: ?float, manager_score: ?float, self_score: ?float, final_score: ?float} */
    public function scores(PerformanceReview $review): array
    {
        $kpi = $this->weighted($review->kpis()->get()->map(fn (PerformanceReviewKpi $k) => [$k->manager_rating, $k->weight]));
        $goal = $this->weighted($review->goals()->get()->map(fn (PerformanceGoal $g) => [$g->manager_rating, $g->weight]));
        $ratings = $review->answers()->where('type', EvaluationFormQuestion::TYPE_RATING)->whereNotNull('manager_rating')->pluck('manager_rating');
        $manager = $ratings->isNotEmpty() ? round((float) $ratings->avg(), 2) : ($review->manager_overall_rating !== null ? (float) $review->manager_overall_rating : null);

        $settings = PerformanceSetting::current();
        $parts = collect([[$kpi, $settings->kpi_weight], [$goal, $settings->goal_weight], [$manager, $settings->manager_weight]])
            ->filter(fn (array $p) => $p[0] !== null && $p[1] > 0);
        $total = $parts->sum(fn (array $p) => $p[1]);
        $final = $total > 0 ? round($parts->sum(fn (array $p) => $p[0] * $p[1]) / $total, 2) : null;

        return ['kpi_score' => $kpi, 'goal_score' => $goal, 'manager_score' => $manager, 'self_score' => $this->selfScore($review), 'final_score' => $final];
    }

    /**
     * Weighted average of [rating, weight] pairs, rated ones only; all
     * weights 0 = a plain average.
     *
     * @param  Collection<int, array{0: ?int, 1: int}>  $pairs
     */
    private function weighted(Collection $pairs): ?float
    {
        $rated = $pairs->filter(fn (array $p) => $p[0] !== null);
        if ($rated->isEmpty()) {
            return null;
        }
        $weights = $rated->sum(fn (array $p) => $p[1]);

        return $weights > 0
            ? round($rated->sum(fn (array $p) => $p[0] * $p[1]) / $weights, 2)
            : round((float) $rated->avg(fn (array $p) => $p[0]), 2);
    }

    /** Average of every self rating — KPIs, goals and form questions. */
    private function selfScore(PerformanceReview $review): ?float
    {
        $ratings = $review->kpis()->pluck('self_rating')
            ->merge($review->goals()->pluck('self_rating'))
            ->merge($review->answers()->pluck('self_rating'))
            ->filter(fn ($r) => $r !== null);

        return $ratings->isNotEmpty() ? round((float) $ratings->avg(), 2) : null;
    }

    /**
     * Writes the allowed fields of each sent KPI / goal / answer that belongs to this review.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, list<string>>  $fields
     */
    private function fill(PerformanceReview $review, array $data, array $fields): void
    {
        $sources = [
            'kpis' => fn () => $review->kpis()->getQuery(),
            'goals' => fn () => PerformanceGoal::query()->where('staff_id', $review->staff_id)->where('performance_cycle_id', $review->performance_cycle_id),
            'answers' => fn () => $review->answers()->getQuery(),
        ];

        foreach ($fields as $key => $allowed) {
            foreach ($data[$key] ?? [] as $row) {
                $model = $sources[$key]()->whereKey($row['id'] ?? 0)->first();
                if ($model !== null) {
                    $model->update(collect($row)->only($allowed)->all());
                }
            }
        }
    }

    /** Every required rating question (and a rating for every KPI / goal) has an answer from this side. */
    private function assertAnswered(PerformanceReview $review, string $side): void
    {
        $missing = $review->answers()->where('is_required', true)->get()->filter(fn (PerformanceReviewAnswer $a) => $a->type === EvaluationFormQuestion::TYPE_RATING
            ? $a->{"{$side}_rating"} === null
            : trim((string) $a->{"{$side}_answer"}) === '')->count();
        $missing += $review->kpis()->whereNull("{$side}_rating")->count();
        $missing += $review->goals()->whereNull("{$side}_rating")->count();

        if ($side === 'manager' && $review->answers()->where('type', EvaluationFormQuestion::TYPE_RATING)->doesntExist()
            && $review->kpis()->doesntExist() && $review->goals()->doesntExist() && $review->manager_overall_rating === null) {
            $missing++;
        }

        if ($missing > 0) {
            throw ValidationException::withMessages(['review' => "Rate or answer everything first — {$missing} still to do."]);
        }
    }

    /** @param  list<string>  $statuses */
    private function assertStatus(PerformanceReview $review, array $statuses, string $message): void
    {
        if (! in_array($review->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => $message]);
        }
    }

    private function notifyStaff(PerformanceReview $review, string $type, string $link): void
    {
        $user = $review->staff?->user;
        if ($user !== null) {
            $this->notifications->notifyMany(collect([$user]), $type, ['cycle' => $review->cycle?->name], link: $link);
        }
    }

    private function notifyReviewer(PerformanceReview $review): void
    {
        $user = $review->reviewer?->user;
        if ($user !== null) {
            $this->notifications->notifyMany(collect([$user]), NotificationType::PERFORMANCE_MANAGER_ASSESSMENT_DUE, ['staff_name' => $review->staff?->fullName(), 'cycle' => $review->cycle?->name], link: '/admin/performance/manager-assessment');
        }
    }
}
