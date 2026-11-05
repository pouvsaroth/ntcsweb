<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\BorrowingRepayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin BorrowingRepayment
 */
class BorrowingRepaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $loan = $this->relationLoaded('borrowing') ? $this->borrowing : null;

        return [
            'id' => $this->id,
            'borrowing_id' => $this->borrowing_id,
            'loan' => $this->when($loan !== null, fn () => [
                'id' => $loan->id,
                'reference' => $loan->reference,
                'currency' => $loan->currency,
                'lender' => $loan->relationLoaded('lender') && $loan->lender ? ['id' => $loan->lender->id, 'name' => $loan->lender->name] : null,
                'borrowed_for' => $loan->relationLoaded('borrowedFor') && $loan->borrowedFor ? ['id' => $loan->borrowedFor->id, 'name' => $loan->borrowedFor->name] : null,
            ]),
            'paid_on' => $this->paid_on?->toDateString(),
            'principal' => $this->principal,
            'interest' => $this->interest,
            'amount' => $this->amount,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'interest_expense_id' => $this->interest_expense_id,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
