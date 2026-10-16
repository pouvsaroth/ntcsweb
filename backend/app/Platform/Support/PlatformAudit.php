<?php

declare(strict_types=1);

namespace App\Platform\Support;

use App\Models\Tenant;
use App\Platform\Models\PlatformRole;
use App\Platform\Models\PlatformStaff;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes bo_audit_logs.
 *
 * Unlike the schools' AuditLogger this does NOT swallow failures: a staff
 * change that cannot be recorded should not happen. Callers write the change
 * and its audit row in one transaction.
 */
final class PlatformAudit
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $action,
        ?Model $target = null,
        array $old = [],
        array $new = [],
        ?int $tenantId = null,
        ?PlatformStaff $actor = null,
    ): void {
        $actor ??= auth('staff')->user();

        DB::connection(config('tenancy.database.central_connection'))->table('bo_audit_logs')->insert([
            'staff_id' => $actor?->getKey(),
            'action' => $action,
            'target_type' => $target ? $this->typeOf($target) : null,
            'target_id' => $target?->getKey(),
            'tenant_id' => $tenantId,
            'old_values' => $old === [] ? null : json_encode($old),
            'new_values' => $new === [] ? null : json_encode($new),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 497),
            'created_at' => now(),
        ]);
    }

    /** What the audit screen shows: "staff #3", not a PHP class name. */
    private function typeOf(Model $target): string
    {
        return match (true) {
            $target instanceof PlatformStaff => 'staff',
            $target instanceof PlatformRole => 'role',
            $target instanceof Tenant => 'school',
            default => Str::snake(class_basename($target)),
        };
    }
}
