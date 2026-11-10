<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\LoanCustomer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LoanCustomer
 */
class LoanCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'id_card_no' => $this->id_card_no,
            'date_of_birth' => $this->date_of_birth?->toDateString(),
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
