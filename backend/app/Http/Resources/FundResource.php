<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fund;
use App\Models\FundTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Fund
 *
 * `total_in` / `total_out` come from withSum. `statement` is only sent for one fund.
 */
class FundResource extends JsonResource
{
    /** @var list<array{entry: FundTransaction, balance: float}>|null */
    public ?array $statement = null;

    public function toArray(Request $request): array
    {
        $in = round((float) ($this->total_in ?? 0), 2);
        $out = round((float) ($this->total_out ?? 0), 2);

        return [
            'id' => $this->id,
            'fund_no' => $this->fund_no,
            'name' => $this->name,
            'fund_type' => $this->fund_type,
            'currency' => $this->currency,
            'status' => $this->status,
            'opened_on' => $this->opened_on?->toDateString(),
            'target_amount' => $this->target_amount !== null ? (float) $this->target_amount : null,
            'description' => $this->description,
            'total_in' => $in,
            'total_out' => $out,
            'balance' => round($in - $out, 2),
            'entries_count' => (int) ($this->entries_count ?? 0),
            'last_entry_on' => $this->last_entry_on ? substr((string) $this->last_entry_on, 0, 10) : null,
            'statement' => $this->when($this->statement !== null, fn () => array_map(fn (array $row) => [
                ...(new FundEntryResource($row['entry']))->toArray($request),
                'balance' => $row['balance'],
            ], $this->statement ?? [])),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
