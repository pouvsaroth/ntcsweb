<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetIssueResource;
use App\Http\Resources\AssetMaintenanceResource;
use App\Http\Resources\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Services\Assets\AssetReportService;
use App\Services\Billing\CurrencyConversionService;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One dashboard for the whole module — folds what the spec calls a separate
 * "Computer Dashboard" and "Repair Dashboard" into category-filterable
 * sections of the same summary, the same consolidation AccountingDashboard
 * uses over Billing's. Every figure is a SQL aggregate — see
 * AssetReportService — never a full asset list loaded to count in PHP.
 */
final class AssetDashboardController extends Controller
{
    public function __construct(private readonly AssetReportService $reports) {}

    public function summary(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::ASSET_REPORTS_VIEW), 403);

        // The money figures again in USD and in KHR (each record at the rate on its own date).
        $both = CurrencyConversionService::both(fn () => [
            'total_investment' => $this->reports->totalInvestment(),
            'total_repair_cost' => $this->reports->totalRepairCost(),
            'shops' => array_column($this->reports->topRepairShops(5), 'total_cost', 'repair_shop_id'),
        ]);
        $shops = array_map(fn (array $shop) => [
            ...$shop,
            'total_cost_usd' => $both['USD']['shops'][$shop['repair_shop_id']] ?? null,
            'total_cost_khr' => $both['KHR']['shops'][$shop['repair_shop_id']] ?? null,
        ], $this->reports->topRepairShops(5));

        return ApiResponse::success([
            'counts_by_status' => $this->reports->countsByStatus(),
            'counts_by_category' => $this->reports->countsByCategory(),
            'counts_by_location' => $this->reports->countsByLocation(),
            // Converted to the school's currency (assets and repairs can be in USD or KHR).
            'currency' => $this->reports->schoolCurrency(),
            'total_investment' => $this->reports->totalInvestment(),
            'total_repair_cost' => $this->reports->totalRepairCost(),
            'total_investment_usd' => $both['USD']['total_investment'],
            'total_investment_khr' => $both['KHR']['total_investment'],
            'total_repair_cost_usd' => $both['USD']['total_repair_cost'],
            'total_repair_cost_khr' => $both['KHR']['total_repair_cost'],
            'open_issues_count' => $this->reports->openIssuesCount(),
            'open_issues_by_priority' => $this->reports->openIssuesByPriority(),
            'open_repairs_count' => $this->reports->openRepairsCount(),
            'assignment_totals' => $this->reports->assignmentTotals(),
            'top_repair_shops' => $shops,
            'recent_issues' => AssetIssueResource::collection($this->reports->recentIssues(10)),
            'upcoming_maintenance' => AssetMaintenanceResource::collection($this->reports->upcomingMaintenance(30, 10)),
            'warranty_expiring' => AssetResource::collection($this->reports->warrantyExpiring(30, 10)),
        ]);
    }
}
