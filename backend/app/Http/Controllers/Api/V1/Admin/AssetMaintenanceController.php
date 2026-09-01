<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CompleteAssetMaintenanceRequest;
use App\Http\Requests\Api\V1\Admin\StoreAssetMaintenanceRequest;
use App\Http\Resources\AssetMaintenanceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Services\Assets\AssetMaintenanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetMaintenanceController extends Controller
{
    public function __construct(private readonly AssetMaintenanceService $maintenance) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetMaintenance::class);

        $records = ApiQuery::for(AssetMaintenance::query()->with(['asset', 'repairShop']), $request)
            ->searchable('maintenance_number', 'maintenance_type')
            ->filterable(['status', 'asset_id', 'repair_shop_id'])
            ->sortable(['scheduled_date', 'created_at'], default: '-scheduled_date')
            ->paginate();

        return ApiResponse::success(AssetMaintenanceResource::collection($records));
    }

    public function store(StoreAssetMaintenanceRequest $request, Asset $asset): JsonResponse
    {
        $maintenance = $this->maintenance->schedule($asset, $request->validated(), $request->user());

        return ApiResponse::created(new AssetMaintenanceResource($maintenance->load(['asset', 'repairShop'])));
    }

    public function show(AssetMaintenance $assetMaintenance): JsonResponse
    {
        $this->authorize('view', $assetMaintenance);

        return ApiResponse::success(new AssetMaintenanceResource($assetMaintenance->load(['asset', 'repairShop'])));
    }

    public function complete(CompleteAssetMaintenanceRequest $request, AssetMaintenance $assetMaintenance): JsonResponse
    {
        $maintenance = $this->maintenance->complete($assetMaintenance, $request->validated(), $request->user());

        return ApiResponse::success(new AssetMaintenanceResource($maintenance));
    }

    public function cancel(AssetMaintenance $assetMaintenance): JsonResponse
    {
        $this->authorize('update', $assetMaintenance);

        $maintenance = $this->maintenance->cancel($assetMaintenance, request()->user());

        return ApiResponse::success(new AssetMaintenanceResource($maintenance));
    }
}
