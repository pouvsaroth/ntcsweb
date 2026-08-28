<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Writes the append-only audit trail.
 *
 * Inserts through the query builder rather than the AuditLog model, for two
 * reasons: platform-level events legitimately carry a NULL tenant_id, which the
 * BelongsToTenant write guard would reject; and this runs on hot paths where
 * skipping model hydration is worth it.
 *
 * Failures here are swallowed and reported. An audit write must never be the
 * reason a user cannot log in — but it must also never fail silently, so it
 * goes to the exception handler.
 */
final class AuditLogger
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly Request $request,
    ) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function log(
        string $event,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        ?User $actor = null,
        ?int $tenantId = null,
    ): void {
        try {
            $actor ??= $this->request->user();

            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId ?? $this->context->id() ?? $actor?->tenant_id,
                'user_id' => $actor?->getKey(),
                'event' => $event,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'old_values' => $old === [] ? null : json_encode($this->redact($old), JSON_THROW_ON_ERROR),
                'new_values' => $new === [] ? null : json_encode($this->redact($new), JSON_THROW_ON_ERROR),
                'ip_address' => $this->request->ip(),
                'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Log an event for an actor whose tenant may not be the resolved one — used
     * by the auth flow, where the audit row must be written for the user's own
     * school even on a failed sign-in.
     */
    public function logFor(string $event, ?int $tenantId, ?User $actor = null, array $new = []): void
    {
        $this->log($event, actor: $actor, new: $new, tenantId: $tenantId);
    }

    /**
     * Credentials and tokens must never reach the audit table — it is the one
     * table designed to be read by school administrators.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function redact(array $values): array
    {
        $sensitive = ['password', 'password_confirmation', 'current_password', 'token', 'remember_token', 'secret'];

        foreach ($values as $key => $value) {
            if (in_array((string) $key, $sensitive, true)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
