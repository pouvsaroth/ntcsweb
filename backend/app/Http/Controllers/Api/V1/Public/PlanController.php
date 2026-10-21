<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Platform\Models\BillingSettings;
use App\Platform\Models\Plan;
use App\Platform\Models\Product;
use Illuminate\Http\Request;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The plans page on NewTech's own website (docs/backoffice/stage-8-signup.md,
 * 8a): the plans staff ticked "Show on the website" in back office → Billing →
 * Plans, and the free trial's length. Only on the website school
 * (`platform.website_school`) — every other school's site gets a 404.
 *
 * Only what a plan card shows: never modules, student counts or codes of
 * plans that are not shown.
 */
final class PlanController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    /** One system's plans (`?system=erp`; default: the school system). */
    public function index(Request $request): JsonResponse
    {
        abort_unless($this->context->getOrFail()->slug === config('platform.website_school'), 404);

        $product = Product::query()->where('code', (string) $request->query('system', Product::SCHOOL))
            ->where('status', Product::STATUS_ACTIVE)->first();
        abort_if($product === null, 404);

        $plans = Plan::query()
            ->where('product_id', $product->id)
            ->where('show_on_website', true)
            ->where('status', Plan::STATUS_ACTIVE)
            ->where('code', '!=', Plan::LEGACY)
            ->orderBy('website_order')
            ->orderBy('price')
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'system' => [
                'code' => $product->code,
                'name' => $product->name,
                'name_km' => $product->name_km,
                'description' => $product->description,
                'description_km' => $product->description_km,
                'logo_url' => $product->logoUrl(),
                'is_school' => $product->code === Product::SCHOOL,
            ],
            // No free-trial plan for this system = no Free trial card.
            'trial_days' => $product->freeTrialPlan() !== null ? BillingSettings::current()->trial_days : 0,
            'plans' => $plans->map(fn (Plan $p) => [
                'code' => $p->code,
                'name' => $p->name,
                'name_km' => $p->name_km,
                'tagline_en' => $p->tagline_en,
                'tagline_km' => $p->tagline_km,
                'features_en' => self::lines($p->features_en),
                'features_km' => self::lines($p->features_km),
                'price' => $p->price,
                'currency' => $p->currency,
                'period_months' => $p->period_months,
                'max_students' => $p->max_students,
                'recommended' => (bool) $p->recommended,
                // Its main menus, for the card (names only — never menus or limits).
                'modules' => array_values(array_map(fn (string $m) => [
                    'label' => \App\Platform\Billing\Modules::catalog()[$m]['label'],
                    'label_km' => \App\Platform\Billing\Modules::catalog()[$m]['label_km'],
                ], array_filter($p->modules ?? [], fn ($m) => isset(\App\Platform\Billing\Modules::catalog()[$m])))),
            ])->values(),
        ]);
    }

    /** @return list<string> */
    private static function lines(?string $text): array
    {
        // A leading "-", "•" or "*" typed by staff is dropped; the card draws its own tick.
        $lines = array_map(fn (string $l) => trim((string) preg_replace('/^[\s\-•·*]+/u', '', $l)), preg_split('/\R/u', (string) $text) ?: []);

        return array_values(array_filter($lines, fn (string $l) => $l !== ''));
    }
}
