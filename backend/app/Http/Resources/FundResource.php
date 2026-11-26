<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Fund;
use App\Models\FundTransaction;
use App\Support\Billing\Money;
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
            ...Money::fields('target_amount', $this->target_amount, $this->currency, null),
            'description' => $this->description,
            'total_in' => $in,
            'total_out' => $out,
            'balance' => round($in - $out, 2),
            // The balance now, in USD and KHR at today's rate.
            ...Money::fields('balance', round($in - $out, 2), $this->currency, null),
            'entries_count' => (int) ($this->entries_count ?? 0),
            'last_entry_on' => $this->last_entry_on ? substr((string) $this->last_entry_on, 0, 10) : null,
            'statement' => $this->when($this->statement !== null, fn () => array_map(fn (array $row) => [
                ...(new FundEntryResource($row['entry']))->toArray($request),
                'balance' => $row['balance'],
                // USD and KHR at the rate on the entry's date.
                ...Money::fields('amount', $row['entry']->amount, $this->currency, $row['entry']->transacted_on),
                ...Money::fields('balance', $row['balance'], $this->currency, $row['entry']->transacted_on),
            ], $this->statement ?? [])),
            // List: total in / out in USD and KHR at today's rate (the statement uses each entry's date instead).
            ...($this->statement !== null ? [] : [
                ...Money::fields('total_in', $in, $this->currency, null),
                ...Money::fields('total_out', $out, $this->currency, null),
            ]),
            ...($this->statement === null ? [] : [
                ...Money::sumFields('total_in', collect($this->statement)->filter(fn (array $row) => $row['entry']->type !== 'out')->map(fn (array $row) => [$row['entry']->amount, $row['entry']->transacted_on]), $this->currency),
                ...Money::sumFields('total_out', collect($this->statement)->filter(fn (array $row) => $row['entry']->type === 'out')->map(fn (array $row) => [$row['entry']->amount, $row['entry']->transacted_on]), $this->currency),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
