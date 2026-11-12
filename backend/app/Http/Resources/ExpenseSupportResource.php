<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ExpenseSupport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ExpenseSupport
 */
class ExpenseSupportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'relationship' => $this->relationship,
            'support_date' => $this->support_date?->toDateString(),
            'kind' => $this->kind,
            'amount' => $this->amount !== null ? (float) $this->amount : null,
            'currency' => $this->currency,
            'items' => $this->items,
            'expense_entity_id' => $this->expense_entity_id,
            'expense_entity' => $this->whenLoaded('expenseEntity', fn () => $this->expenseEntity ? ['id' => $this->expenseEntity->id, 'name' => $this->expenseEntity->name] : null),
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
