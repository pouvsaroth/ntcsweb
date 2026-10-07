<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalaryStructure;
use App\Models\SalaryStructureItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SalaryStructure
 */
class SalaryStructureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'currency' => $this->currency,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn (SalaryStructureItem $item) => [
                'id' => $item->id,
                'payroll_component_id' => $item->payroll_component_id,
                'amount' => $item->amount,
                'component' => $item->component !== null ? [
                    'id' => $item->component->id,
                    'kind' => $item->component->kind,
                    'code' => $item->component->code,
                    'name' => $item->component->name,
                    'calculation' => $item->component->calculation,
                ] : null,
            ])->values()),
            'salaries_count' => $this->whenCounted('salaries'),
        ];
    }
}
