<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'code' => $this->code,
            'logo' => $this->logoUrl(),
            'email' => $this->email,
            'phone' => $this->phone,
            'address' => $this->address,
            'timezone' => $this->timezone,
            'locale' => $this->locale,
            'status' => $this->status,
            'hostname' => $this->hostname(),
            'created_at' => $this->created_at?->toIso8601String(),

            // The settings blob can hold operational configuration, so it is
            // only serialised for someone entitled to manage the school.
            'settings' => $this->when(
                $request->user()?->hasPermission(\App\Support\Authorization\Permissions::TENANT_SETTINGS_VIEW) === true,
                fn () => $this->settings ?? [],
            ),

            'domains' => TenantDomainResource::collection($this->whenLoaded('domains')),
            'users_count' => $this->whenCounted('users'),
        ];
    }
}
