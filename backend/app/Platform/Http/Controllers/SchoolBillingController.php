<?php

declare(strict_types=1);

namespace App\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Platform\Billing\StudentLimit;
use App\Platform\Billing\Subscriptions;
use App\Platform\Models\Plan;
use App\Platform\Models\SchoolSubscription;
use App\Platform\Services\SchoolDatabase;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** One school's Billing tab: current plan, history, subscribe / change / edit / cancel (stage 5a). */
final class SchoolBillingController extends Controller
{
    public function __construct(
        private readonly Subscriptions $subscriptions,
        private readonly SchoolDatabase $database,
        private readonly TenantContext $context,
    ) {}

    public function show(Request $request, Tenant $school): JsonResponse
    {
        return ApiResponse::success($this->payload($school, $this->productId($request)));
    }

    public function subscribe(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'status' => ['required', Rule::in(SchoolSubscription::LIVE)],
            'start_on' => ['nullable', 'date'],
            'end_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = Plan::query()->findOrFail($data['plan_id']);
        $this->subscriptions->subscribe(
            $school,
            $plan,
            $data['status'],
            isset($data['start_on']) ? Carbon::parse($data['start_on']) : null,
            isset($data['end_on']) ? Carbon::parse($data['end_on']) : null,
            $data['notes'] ?? null,
            $request->user('staff'),
        );

        return ApiResponse::success($this->payload($school, $plan->product_id));
    }

    public function changePlan(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $plan = Plan::query()->findOrFail($data['plan_id']);
        $this->subscriptions->changePlan($school, $plan, $data['notes'] ?? null, $request->user('staff'));

        return ApiResponse::success($this->payload($school, $plan->product_id));
    }

    public function update(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate([
            'end_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        // Live, or expired and the latest (more time for an unpaid school — 5e).
        $product = $this->productId($request);
        $current = $this->subscriptions->latest($school, $product);
        abort_if($current === null, 404, __('This school is not on a plan.'));
        $this->subscriptions->update($current, $data, $request->user('staff'));

        return ApiResponse::success($this->payload($school, $product));
    }

    public function cancel(Request $request, Tenant $school): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        $product = $this->productId($request);
        $live = $this->subscriptions->live($school, $product);
        abort_if($live === null, 404, __('This school is not on a plan.'));
        $this->subscriptions->cancel($live, $data['reason'], $request->user('staff'));

        return ApiResponse::success($this->payload($school, $product));
    }

    /** The system a request is about (`product_id`), or null for the customer's main one. */
    private function productId(Request $request): ?int
    {
        $id = $request->input('product_id');

        return $id === null || $id === '' ? null : (int) $id;
    }

    /**
     * One system's plan, history and usage, plus every system the customer has
     * (stage 9). With no system asked for: the main one (school first).
     *
     * @return array<string, mixed>
     */
    private function payload(Tenant $school, ?int $productId = null): array
    {
        $productId ??= $this->subscriptions->latest($school)?->product_id;
        $history = SchoolSubscription::query()
            ->where('tenant_id', $school->getKey())
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId))
            ->with(['plan', 'creator:id,name', 'product'])
            ->latest('id')
            ->get();

        return $this->subscriptions->summary($school, null, $productId) + [
            'product_id' => $productId,
            'systems' => $this->subscriptions->systems($school),
            'history' => $history->map(fn ($s) => Subscriptions::present($s))->values(),
            'usage' => $this->usage($school),
        ];
    }

    /**
     * Active students against the plan's limit (5d), counted inside the
     * school. Null when its database cannot be reached — the tab still opens.
     *
     * @return array{active: int, limit: int|null}|null
     */
    private function usage(Tenant $school): ?array
    {
        if (! $school->isActive() && $school->status !== Tenant::STATUS_SUSPENDED) {
            return null;
        }

        try {
            return $this->database->within($school, fn () => $this->context->runFor($school, fn () => app(StudentLimit::class)->usage()));
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
