<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FundTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FundTransaction
 */
class FundEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fund_id' => $this->fund_id,
            'type' => $this->type,
            'amount' => (float) $this->amount,
            'transacted_on' => $this->transacted_on?->toDateString(),
            'party' => $this->party,
            'fund_provider_id' => $this->fund_provider_id,
            'remark' => $this->remark,
            'cash_account' => $this->whenLoaded('cashAccount', fn () => $this->cashAccount ? ['id' => $this->cashAccount->id, 'code' => $this->cashAccount->code, 'name' => $this->cashAccount->name] : null),
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'posted' => $this->transaction_id !== null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
