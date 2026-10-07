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
