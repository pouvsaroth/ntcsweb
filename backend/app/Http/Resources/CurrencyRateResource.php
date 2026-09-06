<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CurrencyRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CurrencyRate
 */
class CurrencyRateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'effective_date' => $this->effective_date?->toDateString(),
            'khr_per_usd' => (float) $this->khr_per_usd,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
