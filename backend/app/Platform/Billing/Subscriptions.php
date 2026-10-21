<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Models\Tenant;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\PlatformStaff;
use App\Platform\Models\Product;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Support\PlatformAudit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Which plan a school is on, and until when (docs/backoffice/stage-5-billing.md, 5a).
 *
 * Every change is a new row or a status move on the live one — history is
 * never rewritten — and is audited in the same transaction.
 */
final class Subscriptions
{
    public function __construct(private readonly PlatformAudit $audit) {}

    /**
     * The live row for one system; with no system given, any live row — the
     * school system's first (stage 9: a customer may hold several systems).
     */
    public function live(Tenant|int $tenant, ?int $productId = null): ?SchoolSubscription
    {
        return SchoolSubscription::query()
            ->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant)
            ->live()
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->when($productId === null, fn ($q) => $q->orderByRaw('product_id = ? desc', [self::schoolProductId()]))
            ->with(['plan', 'creator:id,name', 'product'])
            ->first();
    }

    /** The live row, or else the most recent one (of one system, when given). */
    public function latest(Tenant|int $tenant, ?int $productId = null): ?SchoolSubscription
    {
        return $this->live($tenant, $productId) ?? SchoolSubscription::query()
            ->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant)
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->with(['plan', 'creator:id,name', 'product'])
            ->latest('id')
            ->first();
    }

    /**
     * Every system the customer has (or had): each one's summary, school system first.
     *
     * @return list<array<string, mixed>>
     */
    public function systems(Tenant|int $tenant): array
    {
        $settings = BillingSettings::current();
        $ids = SchoolSubscription::query()
            ->where('tenant_id', $tenant instanceof Tenant ? $tenant->getKey() : $tenant)
            ->distinct()->pluck('product_id')->filter()->all();

        return Product::query()->whereIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (Product $p) => ['product' => self::presentProduct($p)] + $this->summarise($this->latest($tenant, $p->id), $settings))
            ->values()->all();
    }

    public static function schoolProductId(): int
    {
        static $id = null;

        return $id ??= (int) Product::query()->where('code', Product::SCHOOL)->value('id');
    }

    /** @return array{id: int, code: string, name: string, name_km: ?string} */
    public static function presentProduct(Product $p): array
    {
        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'name_km' => $p->name_km];
    }

    /** A new school: a trial on the settings' trial plan (Legacy if none), for the settings' trial days. */
    public function startTrial(Tenant $tenant, ?PlatformStaff $actor = null): SchoolSubscription
    {
        $settings = BillingSettings::current();
        $plan = $settings->trialPlan()->where('status', Plan::STATUS_ACTIVE)->first() ?? Plan::legacy();
        $start = SchoolSubscription::today();

        return $this->open($tenant, $plan, SchoolSubscription::TRIAL, $start, $start->copy()->addDays(max(1, $settings->trial_days) - 1), null, $actor, 'billing.trial_started');
    }

    /**
     * Put a school with no live subscription on a plan.
     *
     * The end defaults to one period of the plan from the start (none for a
     * plan with no period); a trial's to the settings' trial days.
     */
    public function subscribe(Tenant $tenant, Plan $plan, string $status, ?Carbon $start, ?Carbon $end, ?string $notes, ?PlatformStaff $actor): SchoolSubscription
    {
        if ($this->live($tenant, $plan->product_id)) {
            throw ValidationException::withMessages(['plan_id' => __('This customer already has this system. Change its plan instead.')]);
        }
        $this->assertSellable($plan);

        $start ??= SchoolSubscription::today();
        $end ??= $status === SchoolSubscription::TRIAL
            ? $start->copy()->addDays(max(1, BillingSettings::current()->trial_days) - 1)
            : $this->onePeriodFrom($plan, $start);
        $this->assertDates($start, $end);

        $sub = $this->open($tenant, $plan, $status, $start, $end, $notes, $actor, 'billing.subscribed');

        // On a plan again: a school suspended for billing is let back in (5e).
        app(BillingSuspensions::class)->lift($tenant, __('Put on the plan :plan.', ['plan' => $plan->name]), $actor);

        return $sub;
    }

    /**
     * Move a school to another plan from today. The live row becomes
     * `replaced`; the new one keeps its status and its end date (days already
     * paid are not lost). A plan with no period has no end.
     */
    public function changePlan(Tenant $tenant, Plan $plan, ?string $notes, PlatformStaff $actor): SchoolSubscription
    {
        // A plan change stays inside one system.
        $live = $this->live($tenant, $plan->product_id);
        if (! $live) {
            throw ValidationException::withMessages(['plan_id' => __('This customer does not have this system. Add the system instead.')]);
        }
        if ($live->plan_id === $plan->getKey()) {
            throw ValidationException::withMessages(['plan_id' => __('The school is already on this plan.')]);
        }
        $this->assertSellable($plan);

        $today = SchoolSubscription::today();
        $end = match (true) {
            $plan->period_months === null => null,
            $live->end_on !== null => $live->end_on->copy(),
            default => $this->onePeriodFrom($plan, $today),
        };

        return DB::connection($live->getConnectionName())->transaction(function () use ($tenant, $plan, $live, $today, $end, $notes, $actor) {
            $status = $live->status; // trial stays trial, active stays active
            $live->forceFill(['status' => SchoolSubscription::REPLACED, 'ended_at' => now()])->save();

            return $this->open($tenant, $plan, $status, $today, $end, $notes, $actor, 'billing.plan_changed', [
                'from_plan' => $live->plan?->code,
            ]);
        });
    }

    /** Staff correct the live row's end date or notes. */
    public function update(SchoolSubscription $subscription, array $changes, PlatformStaff $actor): SchoolSubscription
    {
        // The current one: live, or expired and still the latest (staff give
        // more time by moving its end date — 5e).
        $isLatest = ! SchoolSubscription::query()->where('tenant_id', $subscription->tenant_id)->where('id', '>', $subscription->getKey())->exists();
        if (! $subscription->isLive() && ! ($subscription->status === SchoolSubscription::EXPIRED && $isLatest)) {
            throw ValidationException::withMessages(['status' => __('Only the current subscription can be changed.')]);
        }

        $old = ['end_on' => $subscription->end_on?->toDateString(), 'notes' => $subscription->notes, 'status' => $subscription->status];
        if (array_key_exists('end_on', $changes)) {
            $end = $changes['end_on'] === null ? null : Carbon::parse($changes['end_on']);
            $this->assertDates($subscription->start_on, $end);
            $subscription->end_on = $end;

            // An expired plan given an end from today on is active again.
            if ($subscription->status === SchoolSubscription::EXPIRED && ($end === null || $end->gte(SchoolSubscription::today()))) {
                $subscription->status = SchoolSubscription::ACTIVE;
                $subscription->ended_at = null;
            }
        }
        if (array_key_exists('notes', $changes)) {
            $subscription->notes = $changes['notes'];
        }

        DB::connection($subscription->getConnectionName())->transaction(function () use ($subscription, $old, $actor) {
            $subscription->save();
            $this->audit->record('billing.subscription_updated', $subscription, $old, [
                'end_on' => $subscription->end_on?->toDateString(), 'notes' => $subscription->notes, 'status' => $subscription->status,
            ], tenantId: $subscription->tenant_id, actor: $actor);
        });

        if ($old['status'] === SchoolSubscription::EXPIRED && $subscription->status === SchoolSubscription::ACTIVE) {
            app(BillingSuspensions::class)->lift($subscription->tenant, __('End date moved to :date.', ['date' => $subscription->end_on?->format('d-m-Y') ?? '—']), $actor);
        }

        return $subscription->load(['plan', 'creator:id,name']);
    }

    public function cancel(SchoolSubscription $subscription, string $reason, PlatformStaff $actor): SchoolSubscription
    {
        if (! $subscription->isLive()) {
            throw ValidationException::withMessages(['status' => __('Only the current subscription can be cancelled.')]);
        }

        DB::connection($subscription->getConnectionName())->transaction(function () use ($subscription, $reason, $actor) {
            $old = $subscription->status;
            $subscription->forceFill([
                'status' => SchoolSubscription::CANCELLED,
                'ended_at' => now(),
                'notes' => trim(($subscription->notes ? $subscription->notes."\n" : '').__('Cancelled: :reason', ['reason' => $reason])),
            ])->save();
            $this->audit->record('billing.cancelled', $subscription, ['status' => $old], ['status' => SchoolSubscription::CANCELLED, 'reason' => $reason], tenantId: $subscription->tenant_id, actor: $actor);
        });

        return $subscription->load(['plan', 'creator:id,name']);
    }

    /**
     * What the Schools list and the school's Billing tab show at a glance.
     *
     * flag: none | expiring (live, end within the warning days) | expired (end
     * passed, or the latest row expired) | cancelled | no_plan
     *
     * @return array<string, mixed>
     */
    public function summary(Tenant|int $tenant, ?BillingSettings $settings = null, ?int $productId = null): array
    {
        return $this->summarise($this->latest($tenant, $productId), $settings ?? BillingSettings::current());
    }

    /**
     * summary() for a page of schools in two queries.
     *
     * @param  list<int>  $tenantIds
     * @return array<int, array<string, mixed>> keyed by tenant id
     */
    public function summaries(array $tenantIds): array
    {
        $settings = BillingSettings::current();
        $rows = SchoolSubscription::query()
            ->whereIn('tenant_id', $tenantIds)
            ->with(['plan', 'creator:id,name', 'product'])
            ->orderByDesc('id')
            ->get()
            ->groupBy('tenant_id');
        $school = self::schoolProductId();

        $out = [];
        foreach ($tenantIds as $id) {
            $subs = $rows->get($id, collect());
            // The school system's row first, as the list always showed.
            $live = $subs->filter(fn ($s) => $s->isLive())->sortByDesc(fn ($s) => (int) ($s->product_id === $school))->first();
            $out[$id] = $this->summarise($live ?? $subs->first(), $settings) + [
                // Which systems this customer has live (stage 9).
                'systems' => $subs->filter(fn ($s) => $s->isLive() && $s->product)->sortBy(fn ($s) => [$s->product->sort_order, $s->product_id])->map(fn ($s) => self::presentProduct($s->product))->unique('id')->values()->all(),
            ];
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function summarise(?SchoolSubscription $sub, BillingSettings $settings): array
    {
        if (! $sub) {
            return ['flag' => 'no_plan', 'subscription' => null];
        }

        $days = $sub->daysLeft();
        $flag = match (true) {
            $sub->status === SchoolSubscription::EXPIRED => 'expired',
            $sub->status === SchoolSubscription::CANCELLED => 'cancelled',
            ! $sub->isLive() => 'no_plan',
            $days !== null && $days < 0 => 'expired',
            $days !== null && $days <= $settings->warning_days => 'expiring',
            default => 'none',
        };

        return ['flag' => $flag, 'subscription' => self::present($sub)];
    }

    /** @return array<string, mixed> */
    public static function present(SchoolSubscription $sub): array
    {
        return [
            'id' => $sub->id,
            'status' => $sub->status,
            'live' => $sub->isLive(),
            'product' => $sub->product ? self::presentProduct($sub->product) : null,
            'plan' => $sub->plan ? [
                'id' => $sub->plan->id,
                'code' => $sub->plan->code,
                'name' => $sub->plan->name,
                'price' => $sub->plan->price,
                'currency' => $sub->plan->currency,
                'period_months' => $sub->plan->period_months,
                'max_students' => $sub->plan->max_students,
                'modules' => $sub->plan->modules,
            ] : null,
            'start_on' => $sub->start_on?->toDateString(),
            'end_on' => $sub->end_on?->toDateString(),
            'days_left' => $sub->isLive() ? $sub->daysLeft() : null,
            'notes' => $sub->notes,
            'created_by' => $sub->creator?->name,
            'ended_at' => $sub->ended_at?->toIso8601String(),
            'created_at' => $sub->created_at?->toIso8601String(),
        ];
    }

    private function open(Tenant $tenant, Plan $plan, string $status, Carbon $start, ?Carbon $end, ?string $notes, ?PlatformStaff $actor, string $action, array $extra = []): SchoolSubscription
    {
        return DB::connection($plan->getConnectionName())->transaction(function () use ($tenant, $plan, $status, $start, $end, $notes, $actor, $action, $extra) {
            $sub = SchoolSubscription::query()->create([
                'tenant_id' => $tenant->getKey(),
                'plan_id' => $plan->getKey(),
                'status' => $status,
                'start_on' => $start->toDateString(),
                'end_on' => $end?->toDateString(),
                'notes' => $notes,
                'created_by' => $actor?->getKey(),
            ]);

            $this->audit->record($action, $sub, new: $extra + [
                'plan' => $plan->code,
                'status' => $status,
                'start_on' => $start->toDateString(),
                'end_on' => $end?->toDateString(),
            ], tenantId: $tenant->getKey(), actor: $actor);

            // A customer already running that gets new modules gets their starting data.
            if (in_array($tenant->status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED], true)) {
                \App\Platform\Jobs\EnsureModuleData::dispatch((int) $tenant->getKey())->afterCommit();
            }

            return $sub->load(['plan', 'creator:id,name', 'product']);
        });
    }

    /** The last day one period of $plan covers from $start; null for a plan with no period. */
    public function onePeriodFrom(Plan $plan, Carbon $start): ?Carbon
    {
        return $plan->period_months === null ? null : $start->copy()->addMonthsNoOverflow($plan->period_months)->subDay();
    }

    private function assertSellable(Plan $plan): void
    {
        if (! $plan->isActive()) {
            throw ValidationException::withMessages(['plan_id' => __('This plan is inactive.')]);
        }
    }

    private function assertDates(Carbon $start, ?Carbon $end): void
    {
        if ($end !== null && $end->lt($start->copy()->startOfDay())) {
            throw ValidationException::withMessages(['end_on' => __('The end date cannot be before the start date.')]);
        }
    }
}
