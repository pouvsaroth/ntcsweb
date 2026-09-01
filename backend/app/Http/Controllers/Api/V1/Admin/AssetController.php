<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\AssignAssetRequest;
use App\Http\Requests\Api\V1\Admin\ChangeAssetConditionRequest;
use App\Http\Requests\Api\V1\Admin\DisposeAssetRequest;
use App\Http\Requests\Api\V1\Admin\MarkAssetFoundRequest;
use App\Http\Requests\Api\V1\Admin\MarkAssetLostRequest;
use App\Http\Requests\Api\V1\Admin\RetireAssetRequest;
use App\Http\Requests\Api\V1\Admin\ReturnAssetRequest;
use App\Http\Requests\Api\V1\Admin\StoreAssetRequest;
use App\Http\Requests\Api\V1\Admin\TransferAssetRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAssetRequest;
use App\Http\Resources\AssetAssignmentResource;
use App\Http\Resources\AssetHistoryResource;
use App\Http\Resources\AssetResource;
use App\Http\Resources\AssetTransferResource;
use App\Http\Responses\ApiResponse;
use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\AssetHistory;
use App\Models\AssetTransfer;
use App\Models\User;
use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\AssetService;
use App\Support\Assets\AssignableType;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetController extends Controller
{
    public function __construct(
        private readonly AssetService $assets,
        private readonly AssetLifecycleService $lifecycle,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Asset::class);

        $assets = ApiQuery::for(Asset::query()->with(['category', 'location', 'department']), $request)
            ->searchable('asset_number', 'name', 'serial_number', 'asset_tag', 'hostname', 'mac_address')
            ->filterable(['status', 'condition', 'category_id', 'location_id', 'department_id', 'supplier_id'])
            ->sortable(['asset_number', 'name', 'purchase_date', 'purchase_price', 'status', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(AssetResource::collection($assets));
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {
        $asset = $this->assets->create($request->validated(), $request->user());

        return ApiResponse::created(new AssetResource($asset->load(['category', 'location', 'department', 'supplier'])));
    }

    public function show(Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        return ApiResponse::success(new AssetResource(
            $asset->load(['category', 'location', 'department', 'supplier', 'currentAssignment.assignable', 'createdBy'])
        ));
    }

    public function update(UpdateAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->assets->update($asset, $request->validated(), $request->user());

        return ApiResponse::success(new AssetResource($asset->load(['category', 'location', 'department', 'supplier'])));
    }

    public function destroy(Asset $asset): JsonResponse
    {
        $this->authorize('delete', $asset);

        $asset->delete();

        return ApiResponse::noContent();
    }

    public function assign(AssignAssetRequest $request, Asset $asset): JsonResponse
    {
        $data = $request->validated();
        $modelClass = AssignableType::options()[$data['assignable_type']];

        // User does not use BelongsToTenant (see its own docblock), so its
        // lookup needs an explicit tenant scope here — every other
        // assignable type is tenant-scoped automatically.
        $assignable = $modelClass === User::class
            ? User::query()->inTenant($this->context->idOrFail())->findOrFail($data['assignable_id'])
            : $modelClass::query()->findOrFail($data['assignable_id']);

        $assignment = $this->assets->assign($asset, $assignable, $request->user(), $data);

        return ApiResponse::created(new AssetAssignmentResource($assignment->load('assignable')));
    }

    public function returnAsset(ReturnAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->assets->returnAsset($asset, $request->user(), $request->validated());

        return ApiResponse::success(new AssetResource($asset));
    }

    public function transfer(TransferAssetRequest $request, Asset $asset): JsonResponse
    {
        $transfer = $this->assets->transfer($asset, $request->validated(), $request->user());

        return ApiResponse::created(new AssetTransferResource($transfer));
    }

    public function changeCondition(ChangeAssetConditionRequest $request, Asset $asset): JsonResponse
    {
        $data = $request->validated();
        $asset = $this->assets->changeCondition($asset, $data['condition'], $request->user(), $data['notes'] ?? null);

        return ApiResponse::success(new AssetResource($asset));
    }

    public function retire(RetireAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->lifecycle->retire($asset, $request->validated('reason'), $request->user());

        return ApiResponse::success(new AssetResource($asset));
    }

    public function dispose(DisposeAssetRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->lifecycle->dispose($asset, $request->validated(), $request->user());

        return ApiResponse::success(new AssetResource($asset));
    }

    public function markLost(MarkAssetLostRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->lifecycle->markLost($asset, $request->validated(), $request->user());

        return ApiResponse::success(new AssetResource($asset));
    }

    public function markFound(MarkAssetFoundRequest $request, Asset $asset): JsonResponse
    {
        $asset = $this->lifecycle->markFound($asset, $request->user(), $request->validated('notes'));

        return ApiResponse::success(new AssetResource($asset));
    }

    public function history(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        $history = ApiQuery::for(AssetHistory::query()->where('asset_id', $asset->getKey())->with('actor'), $request)
            ->sortable(['occurred_at'], default: '-occurred_at')
            ->paginate();

        return ApiResponse::success(AssetHistoryResource::collection($history));
    }

    public function assignments(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        $assignments = ApiQuery::for(AssetAssignment::query()->where('asset_id', $asset->getKey())->with(['assignable', 'assignedBy']), $request)
            ->sortable(['assigned_date', 'created_at'], default: '-assigned_date')
            ->paginate();

        return ApiResponse::success(AssetAssignmentResource::collection($assignments));
    }

    public function transfers(Request $request, Asset $asset): JsonResponse
    {
        $this->authorize('view', $asset);

        $transfers = ApiQuery::for(AssetTransfer::query()->where('asset_id', $asset->getKey())->with(['fromLocation', 'toLocation', 'fromDepartment', 'toDepartment', 'transferredBy']), $request)
            ->sortable(['transfer_date', 'created_at'], default: '-transfer_date')
            ->paginate();

        return ApiResponse::success(AssetTransferResource::collection($transfers));
    }
}
