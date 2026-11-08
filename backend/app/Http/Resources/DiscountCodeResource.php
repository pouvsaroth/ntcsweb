<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DiscountCode;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * @mixin DiscountCode
 */
class DiscountCodeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $uses = (int) ($this->applied_redemptions_count ?? $this->appliedRedemptions()->count());
        $today = Carbon::now(app(TenantContext::class)->get()?->timezone ?: 'Asia/Phnom_Penh')->toDateString();

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'audience' => $this->audience,
            'discount_type' => $this->discount_type,
            'value' => (float) $this->value,
            'max_discount' => $this->max_discount !== null ? (float) $this->max_discount : null,
            'currency' => $this->currency,
            'starts_on' => $this->starts_on?->toDateString(),
            'expires_on' => $this->expires_on?->toDateString(),
            'max_uses' => $this->max_uses,
            'uses_count' => $uses,
            'status' => $this->status,
            'effective_status' => $this->effectiveStatus($uses, $today),
            'notes' => $this->notes,
            'course_packages' => $this->whenLoaded('coursePackages', fn () => $this->coursePackages->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values()),
            'students' => $this->whenLoaded('students', fn () => $this->students->map(fn ($s) => [
                'id' => $s->id,
                'student_code' => $s->student_code,
                'name' => $s->fullName(),
            ])->values()),
            'students_count' => $this->whenCounted('students'),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
