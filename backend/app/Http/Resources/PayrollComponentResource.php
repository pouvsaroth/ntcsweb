<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayrollComponent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollComponent
 */
class PayrollComponentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'code' => $this->code,
            'name' => $this->name,
            'calculation' => $this->calculation,
            'affects_tax' => $this->affects_tax,
            'affects_social_security' => $this->affects_social_security,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'staff_assignments_count' => $this->whenCounted('staffAssignments'),
            'structure_items_count' => $this->whenCounted('structureItems'),
        ];
    }
}
