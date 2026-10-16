<?php

declare(strict_types=1);

namespace App\Platform\Http\Resources;

use App\Platform\Models\PlatformStaff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformStaff
 */
class StaffResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'must_change_password' => $this->must_change_password,
            'two_factor_enabled' => $this->hasTwoFactor(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($role) => [
                'id' => $role->id,
                'name' => $role->name,
                'is_owner' => $role->is_owner,
            ])->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
