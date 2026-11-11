<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FundProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FundProvider
 */
class FundProviderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'phone' => $this->phone,
            'address' => $this->address,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            // Money given so far, per currency (on the list).
            'given' => $this->when(isset($this->given_usd) || isset($this->given_khr), fn () => [
                'USD' => round((float) ($this->given_usd ?? 0), 2),
                'KHR' => round((float) ($this->given_khr ?? 0), 2),
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
