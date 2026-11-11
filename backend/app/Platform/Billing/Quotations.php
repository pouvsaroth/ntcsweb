<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Models\PlatformStaff;
use App\Platform\Models\Quotation;
use App\Platform\Models\QuotationItem;
use App\Platform\Models\QuotationLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Billing > Quotations: save one (numbering, lines, totals) and present it.
 * Totals are always worked out here, never taken from the screen: USD to the
 * cent, KHR to the whole riel.
 */
final class Quotations
{
    /**
     * @param  array<string, mixed>  $data  validated header fields + `lines`
     */
    public function save(?Quotation $quotation, array $data, ?PlatformStaff $staff): Quotation
    {
        $connection = (new Quotation)->getConnectionName();

        return DB::connection($connection)->transaction(function () use ($quotation, $data, $staff) {
            $lines = $data['lines'] ?? [];
            unset($data['lines']);

            $quotation ??= new Quotation([
                'number' => $this->nextNumber(Carbon::parse($data['issue_date'])),
                'staff_id' => $staff?->getKey(),
            ]);
            $quotation->fill($data);

            $decimals = $quotation->currency === 'KHR' ? 0 : 2;
            $built = $this->buildLines($lines, $decimals);
            $this->applyTotals($quotation, array_sum(array_column($built, 'amount')), $decimals);
            $quotation->save();

            $quotation->lines()->delete();
            foreach ($built as $i => $line) {
                $quotation->lines()->create($line + ['sort_order' => $i + 1]);
            }

            return $quotation->load('lines');
        });
    }

    /** Q-2026-0001, counting within the issue year. */
    public function nextNumber(Carbon $issueDate): string
    {
        $prefix = 'Q-'.$issueDate->format('Y').'-';
        $last = Quotation::query()->where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');
        $next = $last ? ((int) substr($last, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private function buildLines(array $lines, int $decimals): array
    {
        $items = QuotationItem::query()->whereIn('id', array_filter(array_column($lines, 'item_id')))->get()->keyBy('id');
        $all = $items->isEmpty() ? collect() : QuotationItem::query()->get(['id', 'parent_id', 'name', 'name_km'])->keyBy('id');

        return array_map(function (array $line) use ($items, $all, $decimals) {
            $item = isset($line['item_id']) ? $items->get($line['item_id']) : null;
            [$parent, $parentKm] = $item ? $this->parentLabels($item, $all) : [null, null];
            $quantity = round((float) ($line['quantity'] ?? 1), 2);
            $price = round((float) ($line['unit_price'] ?? 0), $decimals);

            return [
                'item_id' => $item?->id,
                'level' => $item?->level,
                'code' => $item?->code,
                'name' => trim((string) ($line['name'] ?? '')) ?: ($item?->name ?? '—'),
                'name_km' => ($line['name_km'] ?? null) ?: $item?->name_km,
                'parent_label' => $parent,
                'parent_label_km' => $parentKm,
                'description' => $line['description'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $price,
                'amount' => round($quantity * $price, $decimals),
            ];
        }, array_values($lines));
    }

    /**
     * "Academic › Programs" for a tab; "Academic" for a submenu; nothing for a menu.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function parentLabels(QuotationItem $item, \Illuminate\Support\Collection $all): array
    {
        $names = [];
        $namesKm = [];
        $parentId = $item->parent_id;
        while ($parentId !== null && ($p = $all->get($parentId)) !== null) {
            array_unshift($names, $p->name);
            array_unshift($namesKm, $p->name_km ?: $p->name);
            $parentId = $p->parent_id;
        }

        return $names === [] ? [null, null] : [implode(' › ', $names), implode(' › ', $namesKm)];
    }

    private function applyTotals(Quotation $q, float $subtotal, int $decimals): void
    {
        $subtotal = round($subtotal, $decimals);
        $value = (float) $q->discount_value;
        $discount = $q->discount_type === 'percent'
            ? round($subtotal * min($value, 100) / 100, $decimals)
            : min(round($value, $decimals), $subtotal);
        $tax = round(($subtotal - $discount) * (float) $q->tax_percent / 100, $decimals);

        $q->subtotal = $subtotal;
        $q->discount_amount = $discount;
        $q->tax_amount = $tax;
        $q->total = round($subtotal - $discount + $tax, $decimals);
    }

    /** @return array<string, mixed> */
    public function present(Quotation $q, bool $withLines = true): array
    {
        $out = [
            'id' => $q->id,
            'number' => $q->number,
            'tenant_id' => $q->tenant_id,
            'customer' => $q->tenant ? ['id' => $q->tenant->id, 'name' => $q->tenant->name, 'slug' => $q->tenant->slug] : null,
            'customer_name' => $q->customer_name,
            'contact_name' => $q->contact_name,
            'phone' => $q->phone,
            'email' => $q->email,
            'address' => $q->address,
            'currency' => $q->currency,
            'issue_date' => $q->issue_date?->toDateString(),
            'valid_until' => $q->valid_until?->toDateString(),
            'discount_type' => $q->discount_type,
            'discount_value' => (float) $q->discount_value,
            'tax_percent' => (float) $q->tax_percent,
            'subtotal' => (float) $q->subtotal,
            'discount_amount' => (float) $q->discount_amount,
            'tax_amount' => (float) $q->tax_amount,
            'total' => (float) $q->total,
            'notes' => $q->notes,
            'status' => $q->status,
            'staff' => $q->staff?->name,
            'created_at' => $q->created_at?->toIso8601String(),
            'updated_at' => $q->updated_at?->toIso8601String(),
        ];

        if ($withLines) {
            $out['lines'] = $q->lines->map(fn (QuotationLine $l) => [
                'id' => $l->id,
                'item_id' => $l->item_id,
                'level' => $l->level,
                'code' => $l->code,
                'name' => $l->name,
                'name_km' => $l->name_km,
                'parent_label' => $l->parent_label,
                'parent_label_km' => $l->parent_label_km,
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'amount' => (float) $l->amount,
            ])->values()->all();
        }

        return $out;
    }
}
