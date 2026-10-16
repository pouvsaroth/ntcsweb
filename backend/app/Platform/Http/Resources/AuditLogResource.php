<?php

declare(strict_types=1);

namespace App\Platform\Http\Resources;

use App\Platform\Models\PlatformAuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PlatformAuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'staff' => $this->staff ? ['id' => $this->staff->id, 'name' => $this->staff->name] : null,
            'target_type' => $this->target_type,
            'target_id' => $this->target_id,
            'tenant_id' => $this->tenant_id,
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
