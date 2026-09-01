<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAssetLocationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAssetLocationRequest;
use App\Http\Resources\AssetLocationResource;
use App\Http\Responses\ApiResponse;
use App\Models\AssetLocation;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetLocationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetLocation::class);

        $locations = ApiQuery::for(AssetLocation::query()->with('parent'), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active', 'type', 'parent_id'])
            ->sortable(['name', 'code', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(AssetLocationResource::collection($locations));
    }

    public function store(StoreAssetLocationRequest $request): JsonResponse
    {
        $location = AssetLocation::query()->create($request->validated());

        return ApiResponse::created(new AssetLocationResource($location->load('parent')));
    }

    public function show(AssetLocation $assetLocation): JsonResponse
    {
        $this->authorize('view', $assetLocation);

        return ApiResponse::success(new AssetLocationResource($assetLocation->load('parent')));
    }

    public function update(UpdateAssetLocationRequest $request, AssetLocation $assetLocation): JsonResponse
    {
        $assetLocation->update($request->validated());

        return ApiResponse::success(new AssetLocationResource($assetLocation->load('parent')));
    }

    public function destroy(AssetLocation $assetLocation): JsonResponse
    {
        $this->authorize('delete', $assetLocation);

        if ($assetLocation->assets()->exists() || $assetLocation->children()->exists()) {
            return ApiResponse::error('This location is in use and cannot be deleted. Deactivate it instead.', 422);
        }

        $assetLocation->delete();

        return ApiResponse::noContent();
    }
}
