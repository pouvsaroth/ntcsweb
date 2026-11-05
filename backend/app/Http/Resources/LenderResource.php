<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lender;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Lender
 */
class LenderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'contact_person' => $this->contact_person,
            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'borrowings_count' => $this->whenCounted('borrowings'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
