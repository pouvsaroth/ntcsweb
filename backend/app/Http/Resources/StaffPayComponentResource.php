<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffPayComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffPayComponent
 */
class StaffPayComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'staff' => $this->whenLoaded('staff', fn () => $this->staff !== null
                ? ['id' => $this->staff->id, 'name' => $this->staff->fullName(), 'employee_code' => $this->staff->employee_code]
                : null),
            'payroll_component_id' => $this->payroll_component_id,
            'component' => $this->whenLoaded('component', fn () => $this->component !== null ? [
                'id' => $this->component->id,
                'kind' => $this->component->kind,
                'code' => $this->component->code,
                'name' => $this->component->name,
                'calculation' => $this->component->calculation,
            ] : null),
            'amount' => $this->amount,
            // The staff member's current salary currency — what a fixed amount is in.
            // (Selected by StaffPayComponentController's query.)
            'currency' => $this->resource->hasAttribute('salary_currency') ? $this->resource->getAttribute('salary_currency') : null,
            'recurrence' => $this->recurrence,
            'starts_on' => $this->starts_on?->toDateString(),
            'ends_on' => $this->ends_on?->toDateString(),
            'note' => $this->note,
        ];
    }
}
