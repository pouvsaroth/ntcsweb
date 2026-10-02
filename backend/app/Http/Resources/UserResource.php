<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Tenancy\TenantContext;
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

            // Whether this account is linked to a Student — its role is
            // always forced to Student in that case (see StoreUserRequest),
            // so the admin Users page hides the role-reassignment control.
            'student_id' => $this->whenLoaded('student', fn () => $this->student?->id),

            // Exposed only on /auth/me and to the acting user themselves; a
            // school admin listing users has no need for the flattened set.
            'permissions' => $this->when(
                $request->user()?->is($this->resource) === true,
                fn () => $this->isSuperAdmin() ? ['*'] : $this->permissionSlugs(),
            ),

            // Approval Flow items this user approves a step of — being in the
            // group is enough to open the Approvals queue for them, no
            // permission needed (see ApprovalFlow). Same visibility as above.
            'approval_flow_types' => $this->when(
                $request->user()?->is($this->resource) === true,
                fn () => $this->tenant_id !== null && app(TenantContext::class)->is($this->tenant_id)
                    ? app(ApprovalFlow::class)->typesFor($this->resource)
                    : [],
            ),
        ];
    }
}
