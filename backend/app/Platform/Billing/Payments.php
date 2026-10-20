<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\Tenant;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\SchoolPayment;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Support\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payments a school made, recorded by staff (docs/backoffice/stage-5-billing.md, 5b).
 *
 * Recording one, in one transaction:
 *   - pays for the school's current subscription (live, or expired and in grace);
 *   - extends it by periods × the plan's months, from the day after its
 *     current end — paying early or late never loses or gains days. A trial
 *     that is paid is paid from the payment date, and becomes active; so does
 *     an expired one;
 *   - takes the next receipt number for the year (R-2026-0001).
 *
 * Voiding keeps the row and does NOT move the end date back: staff change
 * that themselves on the Billing panel if they mean to.
 */
final class Payments
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /** The subscription a payment for this school would pay for, or null. */
    public function payable(Tenant $tenant): ?SchoolSubscription
    {
        $latest = SchoolSubscription::query()
            ->where('tenant_id', $tenant->getKey())
            ->with('plan')
            ->orderByRaw("status in ('trial', 'active') desc")
            ->latest('id')
            ->first();

        return $latest && in_array($latest->status, [...SchoolSubscription::LIVE, SchoolSubscription::EXPIRED], true) ? $latest : null;
    }

    /**
     * @param  array{periods: int, amount?: float|string|null, method: string, reference?: ?string, paid_on?: ?string, notes?: ?string}  $data
     */
    public function record(Tenant $tenant, array $data, ?PlatformStaff $actor): SchoolPayment
    {
        $sub = $this->payable($tenant);
        if (! $sub) {
            throw ValidationException::withMessages(['subscription' => __('This school is not on a plan. Put it on a plan first.')]);
        }

        $plan = $sub->plan;
        $periods = max(1, (int) $data['periods']);
        $paidOn = isset($data['paid_on']) ? Carbon::parse($data['paid_on'])->startOfDay() : SchoolSubscription::today();
        $amount = $data['amount'] ?? null;
        $amount = $amount === null || $amount === '' ? round((float) $plan->price * $periods, 2) : round((float) $amount, 2);

        [$from, $to] = $this->coverage($sub, $periods, $paidOn);

        $payment = DB::connection($sub->getConnectionName())->transaction(function () use ($tenant, $sub, $plan, $data, $periods, $paidOn, $amount, $from, $to, $actor) {
            $endBefore = $sub->end_on?->toDateString();

            $payment = SchoolPayment::query()->create([
                'tenant_id' => $tenant->getKey(),
                'subscription_id' => $sub->getKey(),
                'receipt_number' => $this->nextReceiptNumber($paidOn),
                'amount' => $amount,
                'currency' => $plan->currency,
                'periods' => $periods,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $paidOn->toDateString(),
                'covered_from' => $from?->toDateString(),
                'covered_to' => $to?->toDateString(),
                'end_before' => $endBefore,
                'notes' => $data['notes'] ?? null,
                // Null: paid online by KHQR (stage 8), no staff member.
                'recorded_by' => $actor?->getKey(),
            ]);

            $oldStatus = $sub->status;
            $sub->forceFill([
                'status' => SchoolSubscription::ACTIVE,
                'end_on' => $to?->toDateString() ?? $sub->end_on,
                'ended_at' => null,
            ])->save();

            $this->audit->record('billing.payment_recorded', $payment, new: [
                'receipt_number' => $payment->receipt_number,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'periods' => $periods,
                'method' => $payment->method,
                'subscription' => ['status' => [$oldStatus, SchoolSubscription::ACTIVE], 'end_on' => [$endBefore, $sub->end_on?->toDateString()]],
            ], tenantId: $tenant->getKey(), actor: $actor);

            return $payment;
        });

        // Paid: a school suspended for billing is let back in (5e).
        app(BillingSuspensions::class)->lift($tenant, __('Payment received (:receipt).', ['receipt' => $payment->receipt_number]), $actor);

        return $payment->load(['subscription.plan', 'recorder:id,name', 'voider:id,name', 'tenant']);
    }

    public function void(SchoolPayment $payment, string $reason, PlatformStaff $actor): SchoolPayment
    {
        if ($payment->isVoid()) {
            throw ValidationException::withMessages(['reason' => __('This payment is already void.')]);
        }

        DB::connection($payment->getConnectionName())->transaction(function () use ($payment, $reason, $actor) {
            $payment->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason])->save();
            $this->audit->record('billing.payment_voided', $payment, new: ['receipt_number' => $payment->receipt_number, 'reason' => $reason], tenantId: $payment->tenant_id, actor: $actor);
        });

        return $payment->load(['subscription.plan', 'recorder:id,name', 'voider:id,name', 'tenant']);
    }

    /**
     * What a payment of $periods would cover: [from, to], both null for a plan with no period.
     *
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    public function coverage(SchoolSubscription $sub, int $periods, Carbon $paidOn): array
    {
        $months = $sub->plan?->period_months;
        if ($months === null) {
            return [null, null];
        }

        $from = match (true) {
            // A trial is paid from the day it is paid (never before it began).
            $sub->status === SchoolSubscription::TRIAL => $paidOn->max($sub->start_on)->copy(),
            $sub->end_on !== null => $sub->end_on->copy()->addDay(),
            default => $paidOn->max($sub->start_on)->copy(),
        };

        return [$from->startOfDay(), $from->copy()->addMonthsNoOverflow($months * $periods)->subDay()->startOfDay()];
    }

    /** R-<year>-0001, one sequence per year of the payment date. A transaction-scoped lock keeps two payments from taking one number. */
    private function nextReceiptNumber(Carbon $paidOn): string
    {
        $prefix = 'R-'.$paidOn->format('Y').'-';
        $db = DB::connection((new SchoolPayment)->getConnectionName());
        $db->select("select pg_advisory_xact_lock(hashtext('bo_payments.receipt_number'))");

        $last = $db->table('bo_payments')->where('receipt_number', 'like', $prefix.'%')->max('receipt_number');
        $next = $last === null ? 1 : ((int) substr((string) $last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    /** @return array<string, mixed> */
    public static function present(SchoolPayment $p): array
    {
        return [
            'id' => $p->id,
            'receipt_number' => $p->receipt_number,
            'school' => $p->relationLoaded('tenant') && $p->tenant ? ['id' => $p->tenant->id, 'name' => $p->tenant->name, 'slug' => $p->tenant->slug] : null,
            'plan' => $p->relationLoaded('subscription') ? $p->subscription?->plan?->name : null,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'periods' => $p->periods,
            'method' => $p->method,
            'reference' => $p->reference,
            'paid_on' => $p->paid_on?->toDateString(),
            'covered_from' => $p->covered_from?->toDateString(),
            'covered_to' => $p->covered_to?->toDateString(),
            'notes' => $p->notes,
            'recorded_by' => $p->relationLoaded('recorder') ? $p->recorder?->name : null,
            'void' => $p->isVoid(),
            'voided_at' => $p->voided_at?->toIso8601String(),
            'voided_by' => $p->relationLoaded('voider') ? $p->voider?->name : null,
            'void_reason' => $p->void_reason,
            'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
