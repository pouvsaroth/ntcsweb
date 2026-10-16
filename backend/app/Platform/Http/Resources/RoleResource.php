<?php

declare(strict_types=1);

namespace App\Platform\Http\Resources;

use App\Platform\Models\PlatformRole;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformRole
 */
class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'is_owner' => $this->is_owner,
            'permissions' => $this->grantedPermissions(),
            'staff_count' => $this->whenCounted('staff'),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
