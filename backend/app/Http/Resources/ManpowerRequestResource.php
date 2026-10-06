<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ManpowerRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ManpowerRequest
 */
class ManpowerRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference(),
            'department_id' => $this->department_id,
            'department' => $this->whenLoaded('department', fn () => $this->department?->name),
            'position_id' => $this->position_id,
            'position' => $this->whenLoaded('position', fn () => $this->position?->name),
            'job_title' => $this->job_title,
            'headcount' => $this->headcount,
            'employment_type' => $this->employment_type,
            'needed_by' => $this->needed_by?->toDateString(),
            'reason' => $this->reason,
            'requirements' => $this->requirements,
            'requested_by' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'status' => $this->status,
            // Approvals queue only — see ApprovalFlow::progress().
            'approval_flow' => $this->whenLoaded('approvalFlow'),
            // How many jobs were opened for it — Recruitment's "Open job" action.
            'job_positions_count' => $this->whenCounted('jobPositions'),
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
