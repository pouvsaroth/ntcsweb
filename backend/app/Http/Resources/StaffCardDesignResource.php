<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\StaffCardDesign */
class StaffCardDesignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'card_for' => $this->card_for,
            'name' => $this->name,
            'has_front' => (bool) $this->has_front,
            'has_back' => (bool) $this->has_back,
            'width_mm' => (float) $this->width_mm,
            'height_mm' => (float) $this->height_mm,
            'front_background_url' => $this->url($this->front_background_path),
            'back_background_url' => $this->url($this->back_background_path),
            'signature_url' => $this->url($this->signature_path),
            // For the School logo field.
            'school_logo_url' => app(TenantContext::class)->get()?->logoUrl(),
            'front_fields' => $this->front_fields ?? [],
            'back_fields' => $this->back_fields ?? [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
