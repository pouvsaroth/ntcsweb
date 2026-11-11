<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiscountCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A main discount code: `uses_count` counts its own uses and its students'
 * own codes; `students` = each student given it, with their own code.
 *
 * @mixin DiscountCode
 */
class DiscountCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uses = (int) ($this->applied_redemptions_count ?? $this->appliedRedemptions()->count()) + (int) ($this->child_uses_count ?? 0);
        $today = Carbon::now(app(TenantContext::class)->get()?->timezone ?: 'Asia/Phnom_Penh')->toDateString();

        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'code' => $this->code,
            'name' => $this->name,
            'audience' => $this->audience,
            'discount_type' => $this->discount_type,
            'value' => (float) $this->value,
            'max_discount' => $this->max_discount !== null ? (float) $this->max_discount : null,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'uses_count' => $uses,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus($today),
            'notes' => $this->notes,
            'students' => $this->whenLoaded('children', fn () => $this->children
                ->flatMap(fn (DiscountCode $own) => $own->students->map(fn ($s) => [
                    'id' => $s->id,
                    'student_code' => $s->student_code,
                    'name' => $s->fullName(),
                    'code' => $own->code,
                    'uses_count' => (int) ($own->applied_redemptions_count ?? 0),
                    'assigned_on' => $own->created_at?->toDateString(),
                    'expires_on' => $own->expires_on?->toDateString(),
                    'expired' => $own->expires_on !== null && $own->expires_on->toDateString() < $today,
                ]))
                ->values()),
            'students_count' => $this->assigned_count ?? null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
