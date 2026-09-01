<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FinancialTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FinancialTransaction
 */
class FinancialTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_number' => $this->transaction_number,
            'transaction_date' => $this->transaction_date?->toDateString(),
            'type' => $this->type,
            'debit_account' => $this->whenLoaded('debitAccount', fn () => [
                'id' => $this->debitAccount->id,
                'code' => $this->debitAccount->code,
                'name' => $this->debitAccount->name,
            ]),
            'credit_account' => $this->whenLoaded('creditAccount', fn () => [
                'id' => $this->creditAccount->id,
                'code' => $this->creditAccount->code,
                'name' => $this->creditAccount->name,
            ]),
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'description' => $this->description,
            'reference_type' => $this->reference_type,
            'reference_id' => $this->reference_id,
            'status' => $this->status,
            'reverses_transaction_id' => $this->reverses_transaction_id,
            'created_by' => $this->whenLoaded('createdBy', fn () => $this->createdBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
