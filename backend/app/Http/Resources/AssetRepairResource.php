<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AssetRepair;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AssetRepair
 */
class AssetRepairResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'repair_number' => $this->repair_number,
            'asset_id' => $this->asset_id,
            'asset' => $this->whenLoaded('asset', fn () => $this->asset !== null ? ['id' => $this->asset->id, 'asset_number' => $this->asset->asset_number, 'name' => $this->asset->name] : null),
            'issue_id' => $this->issue_id,
            'repair_shop_id' => $this->repair_shop_id,
            'repair_shop' => $this->whenLoaded('repairShop', fn () => $this->repairShop !== null ? ['id' => $this->repairShop->id, 'name' => $this->repairShop->name] : null),
            'sent_date' => $this->sent_date?->toDateString(),
            'expected_return_date' => $this->expected_return_date?->toDateString(),
            'actual_return_date' => $this->actual_return_date?->toDateString(),
            'problem_description' => $this->problem_description,
            'diagnosis' => $this->diagnosis,
            'repair_description' => $this->repair_description,
            'status' => $this->status,
            'diagnosis_cost' => (float) $this->diagnosis_cost,
            'parts_cost' => (float) $this->parts_cost,
            'labor_cost' => (float) $this->labor_cost,
            'transport_cost' => (float) $this->transport_cost,
            'other_cost' => (float) $this->other_cost,
            'total_cost' => (float) $this->total_cost,
            'warranty_days' => $this->warranty_days,
            'condition_after_repair' => $this->condition_after_repair,
            'decision' => $this->decision,
            'decision_by' => $this->whenLoaded('decisionBy', fn () => $this->decisionBy?->name),
            'decision_date' => $this->decision_date?->toDateString(),
            'decision_reason' => $this->decision_reason,
            'expense_id' => $this->expense_id,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
