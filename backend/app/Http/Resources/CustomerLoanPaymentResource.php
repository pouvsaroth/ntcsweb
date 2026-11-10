<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CustomerLoanPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CustomerLoanPayment
 */
class CustomerLoanPaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_loan_id' => $this->customer_loan_id,
            'paid_on' => $this->paid_on?->toDateString(),
            'principal' => (float) $this->principal,
            'interest' => (float) $this->interest,
            'amount' => (float) $this->amount,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
