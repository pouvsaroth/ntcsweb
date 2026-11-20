<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\User;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\SchoolSubscription;

/**
 * The console banner (docs/backoffice/stage-5-billing.md, 5e): what a school's
 * owner and admins (tenant-settings.update) should know about its plan.
 *
 *   ending    the plan ends within the warning days
 *   ended     the plan ended and is in grace — with the day it will be suspended
 *   students  active students at 90 % of the limit or more
 *
 * Null when there is nothing to say, or for anyone else.
 */
final class BillingNotice
{
    public function __construct(private readonly StudentLimit $students) {}

    /** @return array<string, mixed>|null */
    public function for(User $user, int $tenantId): ?array
    {
        if ($user->isSuperAdmin() || ! $user->hasPermission('tenant-settings.update')) {
            return null;
        }

        $settings = BillingSettings::current();
        $sub = SchoolSubscription::query()
            ->where('tenant_id', $tenantId)
            ->with('plan:id,name')
            ->orderByRaw("status in ('trial', 'active') desc")
            ->latest('id')
            ->first();

        $notice = [];

        if ($sub && $sub->end_on !== null) {
            $days = $sub->daysLeft();
            if ($sub->isLive() && $days !== null && $days >= 0 && $days <= $settings->warning_days) {
                $notice['plan'] = [
                    'kind' => 'ending',
                    'trial' => $sub->status === SchoolSubscription::TRIAL,
                    'plan' => $sub->plan?->name,
                    'end_on' => $sub->end_on->toDateString(),
                    'days_left' => $days,
                ];
            } elseif ($sub->status === SchoolSubscription::EXPIRED || ($sub->isLive() && $days !== null && $days < 0)) {
                $notice['plan'] = [
                    'kind' => 'ended',
                    'trial' => $sub->status === SchoolSubscription::TRIAL,
                    'plan' => $sub->plan?->name,
                    'end_on' => $sub->end_on->toDateString(),
                    // The first day it is suspended (see BillingSuspensions::tick).
                    'suspend_on' => $sub->end_on->copy()->addDays($settings->grace_days + 1)->toDateString(),
                ];
            }
        }

        $limit = $this->students->limit();
        if ($limit !== null) {
            $active = $this->students->activeCount();
            if ($active >= $limit * StudentLimit::WARN_AT) {
                $notice['students'] = ['active' => $active, 'limit' => $limit, 'full' => $active >= $limit];
            }
        }

        return $notice === [] ? null : $notice;
    }
}
