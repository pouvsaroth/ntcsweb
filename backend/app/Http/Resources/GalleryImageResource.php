<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\GalleryImage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape only — the public Gallery page's frontend was already built
 * against a `{id, url, caption}` shape (see PublicGalleryController), which
 * predates this backend and is left as-is rather than reshaped to match.
 *
 * @mixin GalleryImage
 */
class GalleryImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'image_url' => $this->imageUrl(),
            'caption' => $this->caption,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
