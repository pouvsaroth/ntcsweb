<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by both the admin list and the public Promotion page — see
 * HomeSlideResource for the same one-shape-for-both pattern.
 *
 * @mixin Promotion
 */
class PromotionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => $this->imageUrl(),
            'title' => $this->title,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
