<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetHistoryResource;
use App\Http\Resources\AssetMaintenanceResource;
use App\Http\Resources\AssetRepairResource;
use App\Http\Resources\AssetResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetHistory;
use App\Models\AssetMaintenance;
use App\Models\AssetRepair;
use App\Services\Assets\AssetReportService;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;

/**
 * The seven report types (Inventory/Status/Repair/Repair Cost/Maintenance/
 * Assignment/History) as one controller, mirroring AccountingReportController's
 * shape: `?format=csv` exports exactly what the JSON view shows, gated by its
 * own export permission, using the same plain fputcsv approach — no new
 * export library.
 */
final class AssetReportController extends Controller
{
    public function __construct(private readonly AssetReportService $reports) {}

    public function inventory(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $query = ApiQuery::for(Asset::query()->with(['category', 'location', 'department']), $request)
            ->searchable('asset_number', 'name', 'serial_number', 'asset_tag')
            ->filterable(['status', 'condition', 'category_id', 'location_id', 'department_id']);

        if ($request->string('format')->toString() === 'csv') {
            $assets = $query->build()->get();

            return $this->csv('inventory-report', ['Asset Number', 'Name', 'Category', 'Status', 'Condition', 'Location', 'Purchase Price'], $assets->map(fn (Asset $a) => [
                $a->asset_number, $a->name, $a->category?->name, $a->status, $a->condition, $a->location?->name, number_format((float) $a->purchase_price, 2, '.', ''),
            ]));
        }

        return ApiResponse::success(AssetResource::collection($query->sortable(['asset_number', 'name'], default: 'asset_number')->paginate()));
    }

    public function status(Request $request): JsonResponse
    {
        $this->authorizeView($request);

        return ApiResponse::success([
            'counts_by_status' => $this->reports->countsByStatus(),
            'counts_by_category' => $this->reports->countsByCategory(),
            'counts_by_location' => $this->reports->countsByLocation(),
        ]);
    }

    public function repairs(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $query = ApiQuery::for(AssetRepair::query()->with(['asset', 'repairShop']), $request)
            ->searchable('repair_number')
            ->filterable(['status', 'repair_shop_id']);

        if ($request->string('format')->toString() === 'csv') {
            $repairs = $query->build()->get();

            return $this->csv('repair-report', ['Repair Number', 'Asset', 'Repair Shop', 'Status', 'Sent Date', 'Total Cost'], $repairs->map(fn (AssetRepair $r) => [
                $r->repair_number, $r->asset?->asset_number, $r->repairShop?->name, $r->status, (string) $r->sent_date, number_format((float) $r->total_cost, 2, '.', ''),
            ]));
        }

        return ApiResponse::success(AssetRepairResource::collection($query->sortable(['sent_date', 'total_cost'], default: '-sent_date')->paginate()));
    }

    public function repairCost(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $dateFrom = $request->filled('date_from') ? $request->string('date_from')->toString() : null;
        $dateTo = $request->filled('date_to') ? $request->string('date_to')->toString() : null;
        $shops = $this->reports->topRepairShops(50);

        if ($request->string('format')->toString() === 'csv') {
            return $this->csv('repair-cost-report', ['Repair Shop', 'Repair Count', 'Total Cost'], collect($shops)->map(
                fn (array $row) => [$row['repair_shop_name'], $row['repair_count'], number_format($row['total_cost'], 2, '.', '')]
            ));
        }

        return ApiResponse::success([
            'total_repair_cost' => $this->reports->totalRepairCost($dateFrom, $dateTo),
            'by_repair_shop' => $shops,
        ]);
    }

    public function maintenance(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $query = ApiQuery::for(AssetMaintenance::query()->with(['asset', 'repairShop']), $request)
            ->searchable('maintenance_number', 'maintenance_type')
            ->filterable(['status', 'asset_id']);

        if ($request->string('format')->toString() === 'csv') {
            $records = $query->build()->get();

            return $this->csv('maintenance-report', ['Maintenance Number', 'Asset', 'Type', 'Status', 'Scheduled Date', 'Cost'], $records->map(fn (AssetMaintenance $m) => [
                $m->maintenance_number, $m->asset?->asset_number, $m->maintenance_type, $m->status, (string) $m->scheduled_date, number_format((float) ($m->cost ?? 0), 2, '.', ''),
            ]));
        }

        return ApiResponse::success(AssetMaintenanceResource::collection($query->sortable(['scheduled_date'], default: '-scheduled_date')->paginate()));
    }

    public function assignments(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $query = ApiQuery::for(AssetAssignment::query()->with(['asset', 'assignable']), $request)
            ->filterable(['status', 'asset_id']);

        if ($request->string('format')->toString() === 'csv') {
            $rows = $query->build()->get();

            return $this->csv('assignment-report', ['Asset', 'Assigned To', 'Status', 'Assigned Date', 'Returned Date'], $rows->map(fn (AssetAssignment $a) => [
                $a->asset?->asset_number, $a->assignable?->auditDisplayName(), $a->status, (string) $a->assigned_date, (string) $a->returned_date,
            ]));
        }

        return ApiResponse::success(AssetAssignmentResource::collection($query->sortable(['assigned_date'], default: '-assigned_date')->paginate()));
    }

    public function history(Request $request): JsonResponse|HttpResponse
    {
        $this->authorizeView($request);

        $builder = AssetHistory::query()->with(['asset', 'actor']);

        if ($request->filled('date_from')) {
            $builder->whereDate('occurred_at', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $builder->whereDate('occurred_at', '<=', $request->string('date_to')->toString());
        }

        $query = ApiQuery::for($builder, $request)->filterable(['event_type', 'asset_id']);

        if ($request->string('format')->toString() === 'csv') {
            $rows = $query->build()->get();

            return $this->csv('history-report', ['Asset', 'Event', 'Description', 'Occurred At', 'Actor'], $rows->map(fn (AssetHistory $h) => [
                $h->asset?->asset_number, $h->event_type, $h->description, (string) $h->occurred_at, $h->actor?->name,
            ]));
        }

        return ApiResponse::success(AssetHistoryResource::collection($query->sortable(['occurred_at'], default: '-occurred_at')->paginate()));
    }

    private function authorizeView(Request $request): void
    {
        abort_unless($request->user()?->hasPermission(Permissions::ASSET_REPORTS_VIEW), 403);
    }

    private function csv(string $filename, array $headers, Collection $rows): HttpResponse
    {
        abort_unless(request()->user()?->hasPermission(Permissions::ASSET_REPORTS_EXPORT), 403);

        $handle = fopen('php://temp', 'w+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}-".now()->toDateString().'.csv"',
        ]);
    }
}
