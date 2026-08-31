<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'module' => $this->module,
            'description' => $this->description,
            'user' => $this->when($this->user_id !== null, fn () => $this->user !== null ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ] : null),
            'auditable_type' => $this->auditable_type,
            'auditable_id' => $this->auditable_id,
            'record' => $this->recordLabel(),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'request_method' => $this->request_method,
            'request_url' => $this->request_url,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A human-facing identifier for the affected record — tries the live
     * related model first (if still loaded/not deleted), then falls back to
     * whatever identifying field the snapshot itself already carried, so a
     * force-deleted record's audit history still reads sensibly.
     */
    private function recordLabel(): ?string
    {
        if ($this->auditable !== null && method_exists($this->auditable, 'auditDisplayName')) {
            return $this->auditable->auditDisplayName();
        }

        foreach (['student_code', 'employee_code', 'title', 'name'] as $field) {
            $value = $this->new_values[$field] ?? $this->old_values[$field] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $this->auditable_id !== null ? '#'.$this->auditable_id : null;
    }
}
