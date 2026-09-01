<?php

declare(strict_types=1);

namespace App\Services\Assets;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetIssue;
use App\Models\AssetMaintenance;
use App\Models\AssetRepair;
use App\Support\Assets\IssueStatus;
use App\Support\Assets\MaintenanceStatus;
use Illuminate\Support\Facades\DB;

/**
 * Every figure here is a SQL SUM/COUNT/GROUP BY, never a PHP loop over
 * loaded models — mirrors AccountingReportService — so the dashboard and
 * reports stay cheap at the 10,000+ asset / 100,000+ history-row scale the
 * spec calls out, and nothing here ever loads a full asset list into memory
 * just to count it.
 */
final class AssetReportService
{
    /**
     * @return array<string, int>
     */
    public function countsByStatus(): array
    {
        return Asset::query()
            ->select('status', DB::raw('COUNT(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * @return list<array{category_id: int, category_name: string, total: int}>
     */
    public function countsByCategory(): array
    {
        return Asset::query()
            ->join('asset_categories', 'asset_categories.id', '=', 'assets.category_id')
            ->select('asset_categories.id as category_id', 'asset_categories.name as category_name', DB::raw('COUNT(assets.id) as total'))
            ->groupBy('asset_categories.id', 'asset_categories.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category_id' => (int) $row->category_id, 'category_name' => $row->category_name, 'total' => (int) $row->total])
            ->all();
    }

    /**
     * @return list<array{location_id: int, location_name: string, total: int}>
     */
    public function countsByLocation(): array
    {
        return Asset::query()
            ->join('asset_locations', 'asset_locations.id', '=', 'assets.location_id')
            ->select('asset_locations.id as location_id', 'asset_locations.name as location_name', DB::raw('COUNT(assets.id) as total'))
            ->groupBy('asset_locations.id', 'asset_locations.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['location_id' => (int) $row->location_id, 'location_name' => $row->location_name, 'total' => (int) $row->total])
            ->all();
    }

    public function totalInvestment(): float
    {
        return (float) Asset::query()->sum('purchase_price');
    }

    public function totalRepairCost(?string $dateFrom = null, ?string $dateTo = null): float
    {
        $query = AssetRepair::query();

        if ($dateFrom !== null) {
            $query->whereDate('sent_date', '>=', $dateFrom);
        }
        if ($dateTo !== null) {
            $query->whereDate('sent_date', '<=', $dateTo);
        }

        return (float) $query->sum('total_cost');
    }

    /**
     * @return list<array{repair_shop_id: int, repair_shop_name: string, repair_count: int, total_cost: float}>
     */
    public function topRepairShops(int $limit = 10): array
    {
        return AssetRepair::query()
            ->join('repair_shops', 'repair_shops.id', '=', 'asset_repairs.repair_shop_id')
            ->select(
                'repair_shops.id as repair_shop_id',
                'repair_shops.name as repair_shop_name',
                DB::raw('COUNT(asset_repairs.id) as repair_count'),
                DB::raw('SUM(asset_repairs.total_cost) as total_cost'),
            )
            ->groupBy('repair_shops.id', 'repair_shops.name')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'repair_shop_id' => (int) $row->repair_shop_id,
                'repair_shop_name' => $row->repair_shop_name,
                'repair_count' => (int) $row->repair_count,
                'total_cost' => (float) $row->total_cost,
            ])
            ->all();
    }

    public function openIssuesCount(): int
    {
        return AssetIssue::query()->open()->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, AssetIssue>
     */
    public function recentIssues(int $limit = 10)
    {
        return AssetIssue::query()->with('asset')->latest('reported_date')->limit($limit)->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, AssetMaintenance>
     */
    public function upcomingMaintenance(int $days = 30, int $limit = 10)
    {
        return AssetMaintenance::query()
            ->with('asset')
            ->where('status', MaintenanceStatus::SCHEDULED)
            ->whereBetween('scheduled_date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->orderBy('scheduled_date')
            ->limit($limit)
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Asset>
     */
    public function warrantyExpiring(int $days = 30, int $limit = 10)
    {
        return Asset::query()->warrantyExpiringWithin($days)->orderBy('warranty_end_date')->limit($limit)->get();
    }

    public function openRepairsCount(): int
    {
        return AssetRepair::query()->open()->count();
    }

    /**
     * @return array<string, int>
     */
    public function assignmentTotals(): array
    {
        return [
            'active' => AssetAssignment::query()->active()->count(),
            'overdue' => AssetAssignment::query()->active()
                ->whereNotNull('expected_return_date')
                ->whereDate('expected_return_date', '<', now()->toDateString())
                ->count(),
        ];
    }

    public function openIssuesByPriority(): array
    {
        return AssetIssue::query()
            ->where('status', '!=', IssueStatus::CLOSED)
            ->where('status', '!=', IssueStatus::RESOLVED)
            ->where('status', '!=', IssueStatus::CANCELLED)
            ->select('priority', DB::raw('COUNT(*) as total'))
            ->groupBy('priority')
            ->pluck('total', 'priority')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}
