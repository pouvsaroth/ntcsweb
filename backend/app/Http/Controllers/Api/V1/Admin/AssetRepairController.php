<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CancelAssetRepairRequest;
use App\Http\Requests\Api\V1\Admin\CompleteAssetRepairRequest;
use App\Http\Requests\Api\V1\Admin\DecideAssetRepairRequest;
use App\Http\Requests\Api\V1\Admin\SendAssetToRepairRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAssetRepairRequest;
use App\Http\Resources\AssetRepairResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetIssue;
use App\Models\AssetRepair;
use App\Services\Assets\AssetRepairService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetRepairController extends Controller
{
    public function __construct(private readonly AssetRepairService $repairs) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetRepair::class);

        $repairs = ApiQuery::for(AssetRepair::query()->with(['asset', 'repairShop']), $request)
            ->searchable('repair_number')
            ->filterable(['status', 'asset_id', 'repair_shop_id'])
            ->sortable(['sent_date', 'total_cost', 'created_at'], default: '-sent_date')
            ->paginate();

        return ApiResponse::success(AssetRepairResource::collection($repairs));
    }

    public function store(SendAssetToRepairRequest $request, Asset $asset): JsonResponse
    {
        $data = $request->validated();
        $issue = isset($data['issue_id']) ? AssetIssue::query()->findOrFail($data['issue_id']) : null;

        $repair = $this->repairs->sendToRepair($asset, $data, $request->user(), $issue);

        return ApiResponse::created(new AssetRepairResource($repair->load(['asset', 'repairShop'])));
    }

    public function show(AssetRepair $assetRepair): JsonResponse
    {
        $this->authorize('view', $assetRepair);

        return ApiResponse::success(new AssetRepairResource($assetRepair->load(['asset', 'repairShop', 'decisionBy'])));
    }

    public function update(UpdateAssetRepairRequest $request, AssetRepair $assetRepair): JsonResponse
    {
        $repair = $this->repairs->recordProgress($assetRepair, $request->validated(), $request->user());

        return ApiResponse::success(new AssetRepairResource($repair));
    }

    public function complete(CompleteAssetRepairRequest $request, AssetRepair $assetRepair): JsonResponse
    {
        $data = $request->validated();
        $repair = $this->repairs->complete($assetRepair, $data, (int) $data['expense_account_id'], $request->user());

        return ApiResponse::success(new AssetRepairResource($repair->load('repairShop')));
    }

    public function decide(DecideAssetRepairRequest $request, AssetRepair $assetRepair): JsonResponse
    {
        $data = $request->validated();
        $repair = $this->repairs->decide($assetRepair, $data['decision'], $data['reason'], $request->user());

        return ApiResponse::success(new AssetRepairResource($repair));
    }

    public function cancel(CancelAssetRepairRequest $request, AssetRepair $assetRepair): JsonResponse
    {
        $repair = $this->repairs->cancel($assetRepair, $request->validated('reason'), $request->user());

        return ApiResponse::success(new AssetRepairResource($repair));
    }
}
