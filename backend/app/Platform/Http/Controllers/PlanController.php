<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Billing\Modules;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
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
            ->with('product:id,code,name,name_km')
            ->withCount(['subscriptions as live_count' => fn ($q) => $q->whereIn('status', SchoolSubscription::LIVE)])
            ->withCount('subscriptions as used_count')
            ->orderByRaw("status = 'active' desc")
            ->orderBy('price')
            ->orderBy('name')
            ->get();

        return ApiResponse::success([
            'plans' => $plans->map(fn (Plan $p) => $this->present($p))->values(),
            'modules' => Modules::list(),
            // The systems a plan can sell, each with the modules it may use.
            'products' => Product::query()->orderBy('sort_order')->orderBy('id')->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'name_km' => $p->name_km, 'modules' => $p->modules ?? [], 'status' => $p->status])
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $plan = $this->transaction(function () use ($data) {
            $plan = Plan::query()->create($data + ['status' => Plan::STATUS_ACTIVE]);
            $this->onlyRecommended($plan);
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
            $this->onlyRecommended($plan);
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
            // The system it sells; a new plan without one sells the school system.
            'product_id' => ['sometimes', 'integer', Rule::exists((new Product)->getConnectionName().'.bo_products', 'id')],
            'code' => [$required, 'string', 'max:50', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique((new Plan)->getConnectionName().'.bo_plans', 'code')->ignore($plan?->getKey())],
            'name' => [$required, 'string', 'max:100'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'price' => [$required, 'numeric', 'min:0', 'max:9999999999'],
            'currency' => [$required, Rule::in(Plan::CURRENCIES)],
            'period_months' => ['sometimes', 'nullable', 'integer', 'between:1,120'],
            'max_students' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'modules' => [$required, 'array'],
            'modules.*' => ['string', 'distinct', Rule::in(Modules::codes())],
            // Menus inside the modules; null or absent = every menu of each module.
            'menus' => ['sometimes', 'nullable', 'array'],
            'menus.*' => ['string', 'distinct', Rule::in(Modules::groups())],
            'status' => ['sometimes', Rule::in([Plan::STATUS_ACTIVE, Plan::STATUS_INACTIVE])],
            // Where its customers' data is kept: the shared database or one of its own.
            'placement' => ['sometimes', Rule::in([\App\Platform\Models\SchoolPlacement::SHARED, \App\Platform\Models\SchoolPlacement::DEDICATED])],
            // On the website (docs/backoffice/stage-8-signup.md, 8a).
            'show_on_website' => ['sometimes', 'boolean'],
            'name_km' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tagline_en' => ['sometimes', 'nullable', 'string', 'max:150'],
            'tagline_km' => ['sometimes', 'nullable', 'string', 'max:150'],
            'features_en' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'features_km' => ['sometimes', 'nullable', 'string', 'max:3000'],
            'recommended' => ['sometimes', 'boolean'],
            'website_order' => ['sometimes', 'integer', 'between:0,1000'],
        ]);

        if ($plan?->code === Plan::LEGACY) {
            abort_if(isset($data['code']) && $data['code'] !== Plan::LEGACY, 409, __('The Legacy plan keeps its code.'));
            abort_if(($data['status'] ?? Plan::STATUS_ACTIVE) !== Plan::STATUS_ACTIVE, 409, __('The Legacy plan cannot be deactivated.'));
        }
        // The Legacy plan is never sold on the website.
        abort_if(($plan?->code === Plan::LEGACY || ($data['code'] ?? null) === Plan::LEGACY) && ! empty($data['show_on_website']), 409, __('The Legacy plan is not shown on the website.'));
        // A plan offers only modules of its system.
        $product = isset($data['product_id']) ? Product::query()->find($data['product_id']) : ($plan?->product ?? Product::school());
        $modules = $data['modules'] ?? $plan?->modules ?? [];
        $outside = array_values(array_diff($modules, $product->modules ?? []));
        if ($outside !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['modules' => __('Not part of :system: :modules.', ['system' => $product->name, 'modules' => implode(', ', $outside)])]);
        }
        abort_if($plan?->code === Plan::LEGACY && isset($data['product_id']) && $data['product_id'] !== $plan->product_id, 409, __('The Legacy plan stays with the school system.'));
        if (isset($data['modules'])) {
            // Stored in catalog order, so two plans with the same modules read the same.
            $data['modules'] = array_values(array_intersect(Modules::codes(), $data['modules']));
        }
        if (array_key_exists('menus', $data) || isset($data['modules'])) {
            $modules = $data['modules'] ?? $plan?->modules ?? [];
            $menus = array_key_exists('menus', $data) ? $data['menus'] : $plan?->menus;
            $data['menus'] = Modules::normaliseMenus($modules, $menus);
        }

        return $data;
    }

    /** One plan at most carries the Recommended mark: marking one clears the others. */
    private function onlyRecommended(Plan $plan): void
    {
        if ($plan->recommended) {
            Plan::query()->whereKeyNot($plan->getKey())->where('recommended', true)->update(['recommended' => false]);
        }
    }

    private function withLiveCount(Plan $plan): Plan
    {
        return $plan->load('product:id,code,name,name_km')->loadCount(['subscriptions as live_count' => fn ($q) => $q->whereIn('status', SchoolSubscription::LIVE), 'subscriptions as used_count']);
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
            'product' => $plan->product ? ['id' => $plan->product->id, 'code' => $plan->product->code, 'name' => $plan->product->name, 'name_km' => $plan->product->name_km] : null,
            'modules' => $plan->modules ?? [],
            'placement' => $plan->placement ?? 'shared',
            // null = every menu of its modules
            'menus' => $plan->menus,
            'status' => $plan->status,
            'is_legacy' => $plan->code === Plan::LEGACY,
            'live_count' => (int) ($plan->live_count ?? 0),
            // Ever on a customer: then it can only be deactivated, never deleted.
            'used_count' => (int) ($plan->used_count ?? 0),
            'show_on_website' => (bool) $plan->show_on_website,
            'name_km' => $plan->name_km,
            'tagline_en' => $plan->tagline_en,
            'tagline_km' => $plan->tagline_km,
            'features_en' => $plan->features_en,
            'features_km' => $plan->features_km,
            'recommended' => (bool) $plan->recommended,
            'website_order' => (int) $plan->website_order,
        ];
    }

    private function transaction(\Closure $callback): mixed
    {
        return DB::connection((new Plan)->getConnectionName())->transaction($callback);
    }
}
