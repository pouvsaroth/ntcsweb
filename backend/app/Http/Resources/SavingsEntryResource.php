<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavingsTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsTransaction
 */
class SavingsEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'savings_account_id' => $this->savings_account_id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'transacted_on' => $this->transacted_on?->toDateString(),
            'remark' => $this->remark,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'posted' => $this->transaction_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
