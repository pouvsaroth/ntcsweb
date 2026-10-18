<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Billing\Modules;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Support\PlatformAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Plans: price per period, student limit, modules (docs/backoffice/stage-5-billing.md, 5a).
 * A plan with any subscription, past or present, is deactivated instead of deleted.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly PlatformAudit $audit) {}

    public function index(): JsonResponse
    {
        $plans = Plan::query()
            ->withCount(['subscriptions as live_count' => fn ($q) => $q->whereIn('status', SchoolSubscription::LIVE)])
            ->orderByRaw("status = 'active' desc")
            ->orderBy('price')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'plans' => $plans->map(fn (Plan $p) => $this->present($p))->values(),
            'modules' => Modules::list(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $plan = $this->transaction(function () use ($data) {
            $plan = Plan::query()->create($data + ['status' => Plan::STATUS_ACTIVE]);
            $this->audit->record('billing.plan_created', $plan, new: $data);

            return $plan;
        });

        return ApiResponse::created($this->present($this->withLiveCount($plan)));
    }

    public function update(Request $request, Plan $plan): JsonResponse
    {
        $data = $this->validated($request, $plan);
        $old = $plan->only(array_keys($data));

        $this->transaction(function () use ($plan, $data, $old) {
            $plan->update($data);
            $this->audit->record('billing.plan_updated', $plan, $old, $plan->only(array_keys($data)));
        });

        return ApiResponse::success($this->present($this->withLiveCount($plan)));
    }

    /** Deletes a plan nobody has ever been on; otherwise deactivates it, so its history stays readable. */
    public function destroy(Plan $plan): JsonResponse
    {
        abort_if($plan->code === Plan::LEGACY, 409, __('The Legacy plan cannot be removed.'));

        $deleted = $this->transaction(function () use ($plan) {
            if ($plan->subscriptions()->exists()) {
                $plan->update(['status' => Plan::STATUS_INACTIVE]);
                $this->audit->record('billing.plan_deactivated', $plan, ['status' => Plan::STATUS_ACTIVE], ['status' => Plan::STATUS_INACTIVE]);

                return false;
            }
            $this->audit->record('billing.plan_deleted', $plan, $plan->only(['code', 'name']));
            $plan->delete();

            return true;
        });

        return ApiResponse::success(['deleted' => $deleted]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Plan $plan = null): array
    {
        $required = $plan ? 'sometimes' : 'required';

        $data = $request->validate([
            'code' => [$required, 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique((new Plan)->getConnectionName().'.bo_plans', 'code')->ignore($plan?->getKey())],
            'name' => [$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'currency' => [$required, Rule::in(Plan::CURRENCIES)],
            'period_months' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
            'max_students' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'modules' => [$required, 'array'],
            'modules.*' => ['string', 'distinct', Rule::in(Modules::codes())],
            'status' => ['sometimes', Rule::in([Plan::STATUS_ACTIVE, Plan::STATUS_INACTIVE])],
        ]);

        if ($plan?->code === Plan::LEGACY) {
            abort_if(isset($data['code']) && $data['code'] !== Plan::LEGACY, 409, __('The Legacy plan keeps its code.'));
            abort_if(($data['status'] ?? Plan::STATUS_ACTIVE) !== Plan::STATUS_ACTIVE, 409, __('The Legacy plan cannot be deactivated.'));
        }
        if (isset($data['modules'])) {
            // Stored in catalog order, so two plans with the same modules read the same.
            $data['modules'] = array_values(array_intersect(Modules::codes(), $data['modules']));
        }

        return $data;
    }

    private function withLiveCount(Plan $plan): Plan
    {
        return $plan->loadCount(['subscriptions as live_count' => fn ($q) => $q->whereIn('status', SchoolSubscription::LIVE)]);
    }

    /** @return array<string, mixed> */
    private function present(Plan $plan): array
    {
        return [
            'id' => $plan->id,
            'code' => $plan->code,
            'name' => $plan->name,
            'description' => $plan->description,
            'price' => $plan->price,
            'currency' => $plan->currency,
            'period_months' => $plan->period_months,
            'max_students' => $plan->max_students,
            'modules' => $plan->modules ?? [],
            'status' => $plan->status,
            'is_legacy' => $plan->code === Plan::LEGACY,
            'live_count' => (int) ($plan->live_count ?? 0),
        ];
    }

    private function transaction(\Closure $callback): mixed
    {
        return DB::connection((new Plan)->getConnectionName())->transaction($callback);
    }
}
