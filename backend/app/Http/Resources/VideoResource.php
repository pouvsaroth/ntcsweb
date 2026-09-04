<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Video;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Video
 */
class VideoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'course_package_id' => $this->course_package_id,
            'course_package' => $this->whenLoaded('coursePackage', fn () => $this->coursePackage !== null ? ['id' => $this->coursePackage->id, 'name' => $this->coursePackage->name] : null),
            'title' => $this->title,
            'description' => $this->description,
            'video_url' => $this->video_url,
            'thumbnail_url' => $this->thumbnailUrl(),
            'embed_url' => $this->embedUrl(),
            'sort_order' => $this->sort_order,
            'status' => $this->status,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
