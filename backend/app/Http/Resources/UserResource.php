<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatarUrl(),
            'status' => $this->status,
            'locale' => $this->locale,
            'email_verified' => $this->email_verified_at !== null,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),

            // whenLoaded, so a user list never fires a query per row. Callers
            // that need these must eager load them.
            'roles' => RoleResource::collection($this->whenLoaded('roles')),
            'tenant' => new TenantResource($this->whenLoaded('tenant')),

            // Exposed only on /auth/me and to the acting user themselves; a
            // school admin listing users has no need for the flattened set.
            'permissions' => $this->when(
                $request->user()?->is($this->resource) === true,
                fn () => $this->isSuperAdmin() ? ['*'] : $this->permissionSlugs(),
            ),
        ];
    }
}
