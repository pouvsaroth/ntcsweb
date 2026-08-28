<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TenantDomain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TenantDomain
 */
class TenantDomainResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'hostname' => $this->hostname,
            'type' => $this->type,
            'is_primary' => $this->is_primary,
            'verified' => $this->isVerified(),
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
