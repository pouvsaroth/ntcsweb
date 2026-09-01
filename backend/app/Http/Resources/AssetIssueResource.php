<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetIssue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetIssue
 */
class AssetIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_number' => $this->issue_number,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset !== null ? ['id' => $this->asset->id, 'asset_number' => $this->asset->asset_number, 'name' => $this->asset->name] : null),
            'reported_by' => $this->whenLoaded('reportedBy', fn () => $this->reportedBy?->name),
            'reported_date' => $this->reported_date?->toDateString(),
            'priority' => $this->priority,
            'status' => $this->status,
            'title' => $this->title,
            'description' => $this->description,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolved_by' => $this->whenLoaded('resolvedBy', fn () => $this->resolvedBy?->name),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
