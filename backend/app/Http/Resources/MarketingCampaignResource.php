<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\MarketingCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin MarketingCampaign
 *
 * `leads_count` / `enrolled_count` come from withCount (see MarketingCampaignController).
 */
class MarketingCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $leads = (int) ($this->leads_count ?? 0);
        $enrolled = (int) ($this->enrolled_count ?? 0);
        $spent = $this->spent !== null ? (float) $this->spent : null;

        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'channel' => $this->channel,
            'start_on' => $this->start_on?->toDateString(),
            'end_on' => $this->end_on?->toDateString(),
            'currency' => $this->currency,
            'budget' => $this->budget !== null ? (float) $this->budget : null,
            'spent' => $spent,
            'academic_program_id' => $this->academic_program_id,
            'program' => $this->whenLoaded('program', fn () => $this->program ? ['id' => $this->program->id, 'name' => $this->program->name] : null),
            'target' => $this->target,
            'status' => $this->status,
            'description' => $this->description,
            'leads_count' => $leads,
            'enrolled_count' => $enrolled,
            // Cost per lead / per student, from the real cost.
            'cost_per_lead' => $spent !== null && $leads > 0 ? round($spent / $leads, 2) : null,
            'cost_per_student' => $spent !== null && $enrolled > 0 ? round($spent / $enrolled, 2) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
