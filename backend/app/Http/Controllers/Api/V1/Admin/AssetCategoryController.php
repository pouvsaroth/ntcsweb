<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAssetCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAssetCategoryRequest;
use App\Http\Resources\AssetCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AssetCategory;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssetCategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AssetCategory::class);

        $categories = ApiQuery::for(AssetCategory::query()->with('parent'), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active', 'parent_id'])
            ->sortable(['name', 'code', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(AssetCategoryResource::collection($categories));
    }

    public function store(StoreAssetCategoryRequest $request): JsonResponse
    {
        $category = AssetCategory::query()->create($request->validated());

        return ApiResponse::created(new AssetCategoryResource($category->load('parent')));
    }

    public function show(AssetCategory $assetCategory): JsonResponse
    {
        $this->authorize('view', $assetCategory);

        return ApiResponse::success(new AssetCategoryResource($assetCategory->load('parent')));
    }

    public function update(UpdateAssetCategoryRequest $request, AssetCategory $assetCategory): JsonResponse
    {
        $assetCategory->update($request->validated());

        return ApiResponse::success(new AssetCategoryResource($assetCategory->load('parent')));
    }

    public function destroy(AssetCategory $assetCategory): JsonResponse
    {
        $this->authorize('delete', $assetCategory);

        if ($assetCategory->assets()->exists() || $assetCategory->children()->exists()) {
            return ApiResponse::error('This category is in use and cannot be deleted. Deactivate it instead.', 422);
        }

        $assetCategory->delete();

        return ApiResponse::noContent();
    }
}
