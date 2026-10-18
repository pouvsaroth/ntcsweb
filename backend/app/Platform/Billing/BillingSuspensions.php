<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\Tenant;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\School;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Services\SchoolStatus;
use App\Platform\Support\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Warn, then suspend (docs/backoffice/stage-5-billing.md, 5e).
 *
 * The daily tick:
 *   1. a live subscription past its end becomes `expired`;
 *   2. an active school whose subscription has been expired for more than the
 *      grace days is suspended — through SchoolStatus, like any suspension —
 *      and marked as suspended for billing.
 *
 * lift() undoes exactly that, when the school pays or is put on a plan
 * again. A suspension staff made by hand is never lifted by billing, and a
 * school staff let back in is not suspended again for the same subscription.
 */
final class BillingSuspensions
{
    public function __construct(
        private readonly SchoolStatus $status,
        private readonly PlatformAudit $audit,
    ) {}

    /** @return array{expired: int, suspended: int} */
    public function tick(?Carbon $today = null): array
    {
        $today ??= SchoolSubscription::today();
        $settings = BillingSettings::current();

        $expired = 0;
        SchoolSubscription::query()
            ->live()
            ->whereNotNull('end_on')
            ->whereDate('end_on', '<', $today->toDateString())
            ->each(function (SchoolSubscription $sub) use (&$expired) {
                DB::connection($sub->getConnectionName())->transaction(function () use ($sub) {
                    $old = $sub->status;
                    $sub->forceFill(['status' => SchoolSubscription::EXPIRED, 'ended_at' => now()])->save();
                    $this->audit->record('billing.expired', $sub, ['status' => $old], ['status' => SchoolSubscription::EXPIRED, 'end_on' => $sub->end_on?->toDateString()], tenantId: $sub->tenant_id);
                });
                $expired++;
            });

        $suspended = 0;
        $lastDayOfGrace = $today->copy()->subDays($settings->grace_days + 1)->toDateString();

        // Each school's latest subscription, when it is expired and its grace is over.
        $latestIds = SchoolSubscription::query()->selectRaw('max(id)')->groupBy('tenant_id');
        SchoolSubscription::query()
            ->whereIn('id', $latestIds)
            ->where('status', SchoolSubscription::EXPIRED)
            ->whereDate('end_on', '<=', $lastDayOfGrace)
            ->with('tenant')
            ->each(function (SchoolSubscription $sub) use (&$suspended, $settings) {
                $tenant = $sub->tenant;
                if (! $tenant || $tenant->status !== Tenant::STATUS_ACTIVE) {
                    return;
                }
                $school = School::for($tenant);
                if ((int) $school->billing_suspended_subscription_id === (int) $sub->getKey()) {
                    return; // staff already let this school back in for this period
                }

                DB::connection($sub->getConnectionName())->transaction(function () use ($tenant, $school, $sub, $settings) {
                    $this->status->move($tenant, Tenant::STATUS_SUSPENDED, __('Billing: the plan ended on :date and was not paid within :days days.', [
                        'date' => $sub->end_on?->format('d-m-Y'),
                        'days' => $settings->grace_days,
                    ]));
                    $school->forceFill(['billing_suspended_at' => now(), 'billing_suspended_subscription_id' => $sub->getKey()])->save();
                });
                $suspended++;
            });

        return ['expired' => $expired, 'suspended' => $suspended];
    }

    /** Lift a billing suspension (only that kind) once the school pays or is on a plan again. */
    public function lift(Tenant $tenant, string $reason, ?PlatformStaff $actor = null): bool
    {
        $tenant->refresh();
        $school = School::for($tenant);

        if ($tenant->status !== Tenant::STATUS_SUSPENDED || $school->billing_suspended_at === null) {
            return false;
        }

        DB::connection($school->getConnectionName())->transaction(function () use ($tenant, $school, $reason, $actor) {
            $this->status->move($tenant, Tenant::STATUS_ACTIVE, $reason, $actor);
            $school->forceFill(['billing_suspended_at' => null])->save();
        });

        return true;
    }

    /** A school staff move out of suspended by hand is no longer suspended for billing. */
    public function forgetSuspension(Tenant $tenant): void
    {
        School::query()->whereKey($tenant->getKey())->whereNotNull('billing_suspended_at')->update(['billing_suspended_at' => null]);
    }
}
