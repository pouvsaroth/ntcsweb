<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ApprovalRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ApprovalRequest
 */
class ApprovalRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => sprintf('REQ-%06d', $this->id),
            'form_template_id' => $this->form_template_id,
            'template_code' => $this->whenLoaded('template', fn () => $this->template?->code),
            'template_name' => $this->whenLoaded('template', fn () => $this->template?->name),
            'requested_by' => $this->whenLoaded('requester', fn () => $this->requester?->name),
            'subject' => $this->subject,
            'details' => $this->details,
            'status' => $this->status,
            'decision_reason' => $this->decision_reason,
            'decided_by' => $this->whenLoaded('decidedBy', fn () => $this->decidedBy?->name),
            'decided_at' => $this->decided_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
