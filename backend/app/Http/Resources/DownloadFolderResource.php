<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DownloadFolder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Admin shape. `children` (sub-folders) only on a top folder's tree listing.
 *
 * @mixin DownloadFolder
 */
class DownloadFolderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'files_count' => $this->whenCounted('files'),
            'children' => self::collection($this->whenLoaded('children')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
