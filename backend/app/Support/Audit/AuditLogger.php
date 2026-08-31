<?php

declare(strict_types=1);

namespace App\Support\Audit;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the append-only audit trail.
 *
 * Inserts through the query builder rather than the AuditLog model, for two
 * reasons: platform-level events legitimately carry a NULL tenant_id, which the
 * BelongsToTenant write guard would reject; and this runs on hot paths where
 * skipping model hydration is worth it. It also means writing an audit row can
 * never itself trigger Eloquent model events — so nothing here can recurse
 * into another audit write.
 *
 * `event` (e.g. "students.created") is kept for backward compatibility with
 * the rows this already wrote before `action`/`module` existed as their own
 * columns — new code should filter/read `action` and `module` instead.
 *
 * Failures here are swallowed and reported. An audit write must never be the
 * reason a user cannot log in or a school admin cannot save a record — but it
 * must also never fail silently, so it goes to the exception handler.
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
        string $action,
        string $module,
        ?Model $auditable = null,
        array $old = [],
        array $new = [],
        ?string $description = null,
        ?User $actor = null,
        ?int $tenantId = null,
    ): void {
        try {
            $actor ??= $this->request->user();
            $old = $this->redact($old);
            $new = $this->redact($new);

            DB::table('audit_logs')->insert([
                'tenant_id' => $tenantId ?? $this->context->id() ?? $actor?->tenant_id,
                'user_id' => $actor?->getKey(),
                'event' => mb_strtolower($module).'.'.mb_strtolower($action),
                'action' => $action,
                'module' => $module,
                'auditable_type' => $auditable?->getMorphClass(),
                'auditable_id' => $auditable?->getKey(),
                'old_values' => $old === [] ? null : json_encode($old, JSON_THROW_ON_ERROR),
                'new_values' => $new === [] ? null : json_encode($new, JSON_THROW_ON_ERROR),
                'description' => $description ?? $this->describe($action, $module, $auditable),
                'ip_address' => $this->request->ip(),
                'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1000),
                'request_method' => $this->request->method(),
                'request_url' => mb_substr($this->request->fullUrl(), 0, 2048),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /**
     * Log an event for an actor whose tenant may not be the resolved one — used
     * by the auth flow, where the audit row must be written for the user's own
     * school even on a failed sign-in, before any tenant-scoped model exists.
     *
     * @param  array<string, mixed>  $new
     */
    public function logFor(
        string $action,
        string $module,
        ?int $tenantId,
        ?User $actor = null,
        array $new = [],
        ?string $description = null,
    ): void {
        $this->log($action, $module, actor: $actor, new: $new, tenantId: $tenantId, description: $description);
    }

    /**
     * A plain, generic sentence for call sites that don't build their own —
     * good enough for CREATE/UPDATE/DELETE/RESTORE; a business action like
     * ROLE_CHANGE or POSITION_CHANGE should pass its own $description, since
     * only the caller knows the meaningful "from X to Y" of it.
     */
    private function describe(string $action, string $module, ?Model $auditable): string
    {
        $label = mb_strtolower(Str::singular($module));

        $name = match (true) {
            $auditable === null => null,
            method_exists($auditable, 'auditDisplayName') => $auditable->auditDisplayName(),
            default => '#'.$auditable->getKey(),
        };

        $verb = match ($action) {
            AuditAction::CREATE => 'Created',
            AuditAction::UPDATE => 'Updated',
            AuditAction::DELETE => 'Deleted',
            AuditAction::RESTORE => 'Restored',
            default => Str::headline(mb_strtolower($action)),
        };

        return trim("{$verb} {$label} ".($name ?? ''));
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
        $sensitive = [
            'password', 'password_confirmation', 'current_password',
            'token', 'access_token', 'refresh_token', 'remember_token',
            'secret', 'api_key', 'two_factor_secret', 'two_factor_recovery_codes',
        ];

        foreach (array_keys($values) as $key) {
            if (in_array(mb_strtolower((string) $key), $sensitive, true)) {
                $values[$key] = '[redacted]';
            }
        }

        return $values;
    }
}
