<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerLoan;
use App\Services\Borrowings\BorrowingSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerLoan
 *
 * Loan > Owe. `schedule` (each month marked paid / late) and `payments` only
 * for one loan; the list gets the totals, what is still owed and the next
 * unpaid month. Expects `payments_sum_principal` / `payments_sum_interest`
 * (withSum) or the loaded `payments`.
 */
class CustomerLoanResource extends JsonResource
{
    private bool $withSchedule = false;

    public function withSchedule(): self
    {
        $this->withSchedule = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $paidPrincipal = (float) ($this->payments_sum_principal ?? ($this->relationLoaded('payments') ? $this->payments->sum('principal') : $this->payments()->sum('principal')));
        $paidInterest = (float) ($this->payments_sum_interest ?? ($this->relationLoaded('payments') ? $this->payments->sum('interest') : $this->payments()->sum('interest')));

        $plan = BorrowingSchedule::for($this->resource);
        $schedule = BorrowingSchedule::withPayments($plan, $paidPrincipal, now()->toDateString(), $paidInterest);
        $unpaid = collect($schedule)->where('paid', false);
        $next = $this->status === 'active' ? $unpaid->first() : null;
        $outstanding = round((float) $this->amount - $paidPrincipal, 2);

        return [
            'id' => $this->id,
            'loan_no' => $this->loan_no,
            'reference' => $this->reference,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ] : null),
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'lent_on' => $this->lent_on?->toDateString(),
            'interest_type' => $this->interest_type,
            'interest_rate' => (float) $this->interest_rate,
            'interest_period' => $this->interest_period,
            'term_months' => $this->term_months,
            'flexible' => ! $this->term_months,
            'first_payment_on' => $this->first_payment_on?->toDateString(),
            'last_payment_on' => $plan === [] ? null : $plan[array_key_last($plan)]['due_on'],
            'purpose' => $this->purpose,
            'posted' => $this->lent_transaction_id !== null,
            'status' => $this->status,
            'totals' => BorrowingSchedule::totals($plan),
            'monthly_payment' => $plan[0]['total'] ?? 0,
            'paid_principal' => round($paidPrincipal, 2),
            'paid_interest' => round($paidInterest, 2),
            'outstanding' => $outstanding,
            'late_count' => $this->status === 'active' ? $unpaid->where('late', true)->count() : 0,
            'next_payment' => $next,
            'month_interest' => BorrowingSchedule::monthInterest($this->resource, $outstanding),
            'schedule' => $this->when($this->withSchedule, $schedule),
            'payments' => $this->when($this->withSchedule && $this->relationLoaded('payments'), fn () => CustomerLoanPaymentResource::collection($this->payments)),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
