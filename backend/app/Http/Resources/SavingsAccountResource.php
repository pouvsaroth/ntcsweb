<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Support\Billing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SavingsAccount
 *
 * `balance`, `total_in` and `total_out` come from withSum (list) or are set
 * by the controller (one account). `statement` is only sent for one account.
 */
class SavingsAccountResource extends JsonResource
{
    /** @var list<array{entry: SavingsTransaction, balance: float}>|null */
    public ?array $statement = null;

    public function toArray(Request $request): array
    {
        $in = round((float) ($this->total_in ?? 0), 2);
        $out = round((float) ($this->total_out ?? 0), 2);

        return [
            'id' => $this->id,
            'account_no' => $this->account_no,
            'currency' => $this->currency,
            'status' => $this->status,
            'opened_on' => $this->opened_on?->toDateString(),
            'notes' => $this->notes,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer ? [
                'id' => $this->customer->id,
                'code' => $this->customer->code,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
                'gender' => $this->customer->gender,
            ] : null),
            'total_in' => $in,
            'total_out' => $out,
            'balance' => round($in - $out, 2),
            // The balance now, in USD and KHR at today's rate.
            ...Money::fields('balance', round($in - $out, 2), $this->currency, null),
            'entries_count' => (int) ($this->entries_count ?? 0),
            'last_entry_on' => $this->last_entry_on ? substr((string) $this->last_entry_on, 0, 10) : null,
            'statement' => $this->when($this->statement !== null, fn () => array_map(fn (array $row) => [
                ...(new SavingsEntryResource($row['entry']))->toArray($request),
                'balance' => $row['balance'],
                // USD and KHR at the rate on the entry's date.
                ...Money::fields('amount', $row['entry']->amount, $this->currency, $row['entry']->transacted_on),
                ...Money::fields('balance', $row['balance'], $this->currency, $row['entry']->transacted_on),
            ], $this->statement ?? [])),
            ...($this->statement === null ? [] : [
                ...Money::sumFields('total_in', collect($this->statement)->filter(fn (array $row) => $row['entry']->type !== 'withdrawal')->map(fn (array $row) => [$row['entry']->amount, $row['entry']->transacted_on]), $this->currency),
                ...Money::sumFields('total_out', collect($this->statement)->filter(fn (array $row) => $row['entry']->type === 'withdrawal')->map(fn (array $row) => [$row['entry']->amount, $row['entry']->transacted_on]), $this->currency),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
