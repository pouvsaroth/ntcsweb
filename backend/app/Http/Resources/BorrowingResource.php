<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Borrowing;
use App\Services\Borrowings\BorrowingSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Borrowing
 *
 * `schedule` (each month marked paid / late) and `repayments` only when asked
 * for (one loan); the list gets the totals, what is still owed and the next
 * unpaid month from the same plan. Expects `repayments_sum_principal` /
 * `repayments_sum_interest` (withSum) or the loaded `repayments`.
 */
class BorrowingResource extends JsonResource
{
    private bool $withSchedule = false;

    public function withSchedule(): self
    {
        $this->withSchedule = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $paidPrincipal = (float) ($this->repayments_sum_principal ?? ($this->relationLoaded('repayments') ? $this->repayments->sum('principal') : $this->repayments()->sum('principal')));
        $paidInterest = (float) ($this->repayments_sum_interest ?? ($this->relationLoaded('repayments') ? $this->repayments->sum('interest') : $this->repayments()->sum('interest')));

        $plan = BorrowingSchedule::for($this->resource);
        $schedule = BorrowingSchedule::withPayments($plan, $paidPrincipal, now()->toDateString());
        $unpaid = collect($schedule)->where('paid', false);
        $next = $this->status === 'active' ? $unpaid->first() : null;

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'lender' => $this->whenLoaded('lender', fn () => $this->lender ? ['id' => $this->lender->id, 'name' => $this->lender->name, 'type' => $this->lender->type] : null),
            'borrowed_for' => $this->whenLoaded('borrowedFor', fn () => $this->borrowedFor ? ['id' => $this->borrowedFor->id, 'name' => $this->borrowedFor->name] : null),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'amount' => $this->amount,
            'currency' => $this->currency,
            'received_on' => $this->received_on?->toDateString(),
            'interest_type' => $this->interest_type,
            'interest_rate' => $this->interest_rate,
            'interest_period' => $this->interest_period,
            'term_months' => $this->term_months,
            'flexible' => ! $this->term_months,
            'first_payment_on' => $this->first_payment_on?->toDateString(),
            'last_payment_on' => $plan === [] ? null : $plan[array_key_last($plan)]['due_on'],
            'purpose' => $this->purpose,
            'contract_name' => $this->contract_name,
            'has_contract' => $this->contract_path !== null,
            'posted' => $this->receipt_transaction_id !== null,
            'status' => $this->status,
            'totals' => BorrowingSchedule::totals($plan),
            'monthly_payment' => $plan[0]['total'] ?? 0,
            'paid_principal' => round($paidPrincipal, 2),
            'paid_interest' => round($paidInterest, 2),
            'outstanding' => round((float) $this->amount - $paidPrincipal, 2),
            'late_count' => $this->status === 'active' ? $unpaid->where('late', true)->count() : 0,
            'next_payment' => $next,
            'month_interest' => BorrowingSchedule::monthInterest($this->resource, (float) $this->amount - $paidPrincipal),
            'schedule' => $this->when($this->withSchedule, $schedule),
            'repayments' => $this->when($this->withSchedule && $this->relationLoaded('repayments'), fn () => BorrowingRepaymentResource::collection($this->repayments)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
