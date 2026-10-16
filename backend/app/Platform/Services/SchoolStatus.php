<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Tenant;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolStatusChange;
use App\Platform\Support\PlatformAudit;
use Illuminate\Validation\ValidationException;

/**
 * Every change of a school's status goes through here, so each one is
 * allowed, recorded in the school's history and audited — the same way.
 *
 *   provisioning ──▶ active | failed           (the job)
 *   failed       ──▶ provisioning              (retry)
 *   active       ──▶ suspended | archived
 *   suspended    ──▶ active | archived
 *   archived     ──▶ (nothing — the slug and database are kept forever)
 */
final class SchoolStatus
{
    private const ALLOWED = [
        Tenant::STATUS_PROVISIONING => [Tenant::STATUS_ACTIVE, Tenant::STATUS_FAILED],
        Tenant::STATUS_FAILED => [Tenant::STATUS_PROVISIONING],
        Tenant::STATUS_ACTIVE => [Tenant::STATUS_SUSPENDED, Tenant::STATUS_ARCHIVED],
        Tenant::STATUS_SUSPENDED => [Tenant::STATUS_ACTIVE, Tenant::STATUS_ARCHIVED],
        Tenant::STATUS_ARCHIVED => [],
    ];

    public function __construct(private readonly PlatformAudit $audit) {}

    public function canMove(Tenant $tenant, string $to): bool
    {
        return in_array($to, self::ALLOWED[$tenant->status] ?? [], true);
    }

    /** @throws ValidationException when the move is not allowed */
    public function move(Tenant $tenant, string $to, ?string $reason = null, ?PlatformStaff $staff = null): void
    {
        if (! $this->canMove($tenant, $to)) {
            throw ValidationException::withMessages([
                'status' => __('A school that is :from cannot become :to.', ['from' => $tenant->status, 'to' => $to]),
            ]);
        }

        $from = $tenant->status;
        $tenant->forceFill(['status' => $to])->save();

        SchoolStatusChange::query()->create([
            'tenant_id' => $tenant->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'reason' => $reason,
            'staff_id' => $staff?->getKey(),
        ]);

        $this->audit->record('school.status_changed', $tenant, ['status' => $from], array_filter([
            'status' => $to,
            'reason' => $reason,
        ]), tenantId: $tenant->getKey(), actor: $staff);
    }
}
