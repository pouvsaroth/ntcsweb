<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DownloadFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Shared by the admin Upload page and the public Download page.
 *
 * @mixin DownloadFile
 */
class DownloadFileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'extension' => $this->extension(),
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'is_image' => $this->isImage(),
            // For the picture preview only — downloads go through the API (see PublicDownloadController::download()).
            'url' => $this->url(),
            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
