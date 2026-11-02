<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffSalary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StaffSalary
 */
class StaffSalaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'staff_id' => $this->staff_id,
            'basic_salary' => $this->basic_salary,
            'currency' => $this->currency,
            'salary_structure_id' => $this->salary_structure_id,
            'structure' => $this->whenLoaded('structure', fn () => $this->structure !== null
                ? ['id' => $this->structure->id, 'code' => $this->structure->code, 'name' => $this->structure->name]
                : null),
            // "Pay by shift" lines; empty for a plain monthly salary.
            'shifts' => $this->whenLoaded('shifts', fn () => $this->shifts->map(fn ($line) => [
                'id' => $line->id,
                'shift_id' => $line->shift_id,
                'shift' => $line->shift !== null ? ['id' => $line->shift->id, 'code' => $line->shift->code, 'name' => $line->shift->name, 'color' => $line->shift->color] : null,
                'amount' => $line->amount,
            ])->values()),
            'effective_from' => $this->effective_from?->toDateString(),
            'payment_method' => $this->payment_method,
            'bank_name' => $this->bank_name,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'note' => $this->note,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
