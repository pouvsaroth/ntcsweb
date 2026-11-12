<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\MarketingCampaign;
use App\Services\Marketing\MarketingReport;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Marketing > Report (campaigns.view), and the dashboard's Marketing card
 * (dashboard.cards.marketing or campaigns.view): leads, enrolled, conversion.
 */
final class MarketingReportController extends Controller
{
    public function __construct(private readonly MarketingReport $report) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', MarketingCampaign::class);
        [$from, $to] = $this->period($request);

        return ApiResponse::success($this->report->build($from, $to));
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->hasPermission(Permissions::DASHBOARD_CARDS_MARKETING) || $user->hasPermission(Permissions::CAMPAIGNS_VIEW)), 403);
        [$from, $to] = $this->period($request);

        return ApiResponse::success($this->report->summary($from, $to));
    }

    /** @return array{0: string, 1: string} Defaults to this month. */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [$data['from'] ?? now()->startOfMonth()->toDateString(), $data['to'] ?? now()->endOfMonth()->toDateString()];
    }
}
