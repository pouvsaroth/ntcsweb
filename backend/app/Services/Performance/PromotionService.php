<?php

declare(strict_types=1);

namespace App\Services\Performance;

use App\Models\PromotionRecommendation;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A promotion recommendation's life — see PromotionRecommendation:
 *
 * - recommend(): pending, sent to approval (the Promotion Approval Flow's
 *   first group, or whoever holds performance.approve-promotion). What the
 *   staff member has now is kept on it.
 * - approve() / reject(): see ApprovalFlow (step by step with a flow).
 *   Approved and already due → applied straight away.
 * - apply(): the new position / job grade / job level on the staff record,
 *   and the new basic salary as a Payroll salary from the effective date
 *   (copying their current one's currency, structure and bank details).
 *   The staff member is told. applyDue() runs every approved one whose date
 *   has come (daily — see performance:apply-promotions).
 */
final class PromotionService
{
    public function __construct(
        private readonly ApprovalFlow $flow,
        private readonly NotificationService $notifications,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function recommend(Staff $staff, array $data, User $actor): PromotionRecommendation
    {
        $changes = collect(['position_id', 'job_grade_id', 'job_level_id'])
            ->filter(fn (string $field) => ($data["to_{$field}"] ?? null) !== null && (int) $data["to_{$field}"] !== (int) $staff->{$field});
        if ($changes->isEmpty() && ($data['new_basic_salary'] ?? null) === null) {
            throw ValidationException::withMessages(['to_position_id' => 'Choose a new position, job grade or job level, or a new salary.']);
        }

        $open = PromotionRecommendation::query()->where('staff_id', $staff->id)->whereIn('status', [PromotionRecommendation::STATUS_PENDING, PromotionRecommendation::STATUS_APPROVED])->exists();
        if ($open) {
            throw ValidationException::withMessages(['staff_id' => 'This staff member already has a promotion waiting for approval or to take effect.']);
        }

        $salary = StaffSalary::query()->where('staff_id', $staff->id)->effectiveOn(now()->toDateString())->first();
        if (($data['new_basic_salary'] ?? null) !== null && $salary === null) {
            throw ValidationException::withMessages(['new_basic_salary' => 'Set this staff member\'s basic salary in Payroll first — the new salary follows its currency.']);
        }

        $recommendation = PromotionRecommendation::query()->create([
            'staff_id' => $staff->id,
            'performance_review_id' => $data['performance_review_id'] ?? null,
            'from_position_id' => $staff->position_id,
            'to_position_id' => $data['to_position_id'] ?? null,
            'from_job_grade_id' => $staff->job_grade_id,
            'to_job_grade_id' => $data['to_job_grade_id'] ?? null,
            'from_job_level_id' => $staff->job_level_id,
            'to_job_level_id' => $data['to_job_level_id'] ?? null,
            'from_basic_salary' => $salary?->basic_salary,
            'new_basic_salary' => $data['new_basic_salary'] ?? null,
            'salary_currency' => $salary?->currency,
            'effective_date' => $data['effective_date'],
            'reason' => $data['reason'],
            'requested_by' => $actor->getKey(),
        ]);

        $this->notifyApprovers($recommendation, $this->flow->submitRecipients(
            $recommendation,
            fn () => $this->notifications->usersWithPermission(Permissions::PERFORMANCE_APPROVE_PROMOTION),
        ));

        return $recommendation;
    }

    public function approve(PromotionRecommendation $recommendation, User $actor): PromotionRecommendation
    {
        $this->flow->authorizeDecision($recommendation, $actor, 'approve');

        return $this->flow->approve(
            $recommendation,
            $actor,
            fn (PromotionRecommendation $doc) => $this->finalApprove($doc, $actor),
            fn (PromotionRecommendation $doc, Collection $next) => $this->notifyApprovers($doc, $next),
        );
    }

    public function reject(PromotionRecommendation $recommendation, string $reason, User $actor): PromotionRecommendation
    {
        $this->flow->authorizeDecision($recommendation, $actor, 'reject');

        $recommendation = DB::connection('tenant')->transaction(function () use ($recommendation, $reason, $actor) {
            $locked = PromotionRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PromotionRecommendation::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This recommendation is not waiting for approval.']);
            }
            $locked->update(['status' => PromotionRecommendation::STATUS_REJECTED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => $reason]);

            return $locked->fresh();
        });

        $this->notifyRequester($recommendation, NotificationType::PROMOTION_REJECTED, ['reason' => $reason]);

        return $recommendation;
    }

    public function cancel(PromotionRecommendation $recommendation): PromotionRecommendation
    {
        if (! in_array($recommendation->status, [PromotionRecommendation::STATUS_PENDING, PromotionRecommendation::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['status' => 'Only a pending or not yet applied recommendation can be cancelled.']);
        }

        $recommendation->update(['status' => PromotionRecommendation::STATUS_CANCELLED]);

        return $recommendation->fresh();
    }

    public function apply(PromotionRecommendation $recommendation): PromotionRecommendation
    {
        $recommendation = DB::connection('tenant')->transaction(function () use ($recommendation) {
            $locked = PromotionRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PromotionRecommendation::STATUS_APPROVED) {
                return $locked;
            }

            $staff = Staff::query()->findOrFail($locked->staff_id);
            $changes = array_filter([
                'position_id' => $locked->to_position_id,
                'job_grade_id' => $locked->to_job_grade_id,
                'job_level_id' => $locked->to_job_level_id,
            ], fn ($value) => $value !== null);
            if ($changes !== []) {
                $staff->update($changes);
            }

            if ($locked->new_basic_salary !== null) {
                $date = $locked->effective_date->toDateString();
                $current = StaffSalary::query()->where('staff_id', $staff->id)->effectiveOn($date)->first()
                    ?? StaffSalary::query()->where('staff_id', $staff->id)->orderByDesc('effective_from')->first();
                StaffSalary::query()->updateOrCreate(
                    ['staff_id' => $staff->id, 'effective_from' => $date],
                    [
                        'basic_salary' => $locked->new_basic_salary,
                        'currency' => $locked->salary_currency ?? $current?->currency,
                        'salary_structure_id' => $current?->salary_structure_id,
                        'payment_method' => $current?->payment_method ?? StaffSalary::PAYMENT_BANK,
                        'bank_name' => $current?->bank_name,
                        'bank_account_name' => $current?->bank_account_name,
                        'bank_account_number' => $current?->bank_account_number,
                        'note' => "Promotion #{$locked->id}",
                        'created_by' => $locked->decided_by,
                    ],
                );
            }

            $locked->update(['status' => PromotionRecommendation::STATUS_APPLIED, 'applied_at' => now()]);

            return $locked->fresh();
        });

        if ($recommendation->status === PromotionRecommendation::STATUS_APPLIED) {
            $user = $recommendation->staff?->user;
            if ($user !== null) {
                $this->notifications->notifyMany(collect([$user]), NotificationType::PROMOTION_APPLIED, [
                    'position' => $recommendation->toPosition?->name,
                    'date' => $recommendation->effective_date->toDateString(),
                ]);
            }
        }

        return $recommendation;
    }

    /** Every approved recommendation whose effective date has come. */
    public function applyDue(): int
    {
        $due = PromotionRecommendation::query()
            ->where('status', PromotionRecommendation::STATUS_APPROVED)
            ->whereDate('effective_date', '<=', now()->toDateString())
            ->get();

        foreach ($due as $recommendation) {
            $this->apply($recommendation);
        }

        return $due->count();
    }

    private function finalApprove(PromotionRecommendation $recommendation, User $actor): PromotionRecommendation
    {
        $recommendation = DB::connection('tenant')->transaction(function () use ($recommendation, $actor) {
            $locked = PromotionRecommendation::query()->whereKey($recommendation->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PromotionRecommendation::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This recommendation is not waiting for approval.']);
            }
            $locked->update(['status' => PromotionRecommendation::STATUS_APPROVED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => null]);

            return $locked->fresh();
        });

        $this->notifyRequester($recommendation, NotificationType::PROMOTION_APPROVED);

        return $recommendation->effective_date->toDateString() <= now()->toDateString() ? $this->apply($recommendation) : $recommendation;
    }

    /** @param  Collection<int, User>  $recipients */
    private function notifyApprovers(PromotionRecommendation $recommendation, Collection $recipients): void
    {
        $this->notifications->notifyMany($recipients, NotificationType::PROMOTION_SUBMITTED, ['staff_name' => $recommendation->staff?->fullName()], link: '/admin/performance/promotions');
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyRequester(PromotionRecommendation $recommendation, string $type, array $extra = []): void
    {
        $requester = $recommendation->requested_by !== null ? User::query()->find($recommendation->requested_by) : null;
        if ($requester !== null) {
            $this->notifications->notifyMany(collect([$requester]), $type, ['staff_name' => $recommendation->staff?->fullName(), ...$extra], link: '/admin/performance/promotions');
        }
    }
}
