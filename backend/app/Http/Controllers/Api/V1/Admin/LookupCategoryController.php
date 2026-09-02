<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreLookupCategoryRequest;
use App\Http\Requests\Api\V1\Admin\UpdateLookupCategoryRequest;
use App\Http\Resources\LookupCategoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\LookupCategory;
use App\Services\BaseData\LookupCache;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LookupCategoryController extends Controller
{
    public function __construct(
        private readonly LookupCache $cache,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LookupCategory::class);

        $categories = ApiQuery::for(LookupCategory::query()->withCount('values'), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['sort_order', 'name', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(LookupCategoryResource::collection($categories));
    }

    public function store(StoreLookupCategoryRequest $request): JsonResponse
    {
        $category = LookupCategory::query()->create($request->validated());
        $this->cache->invalidateTenant($this->context->idOrFail());

        return ApiResponse::created(new LookupCategoryResource($category));
    }

    public function show(LookupCategory $lookupCategory): JsonResponse
    {
        $this->authorize('view', $lookupCategory);

        return ApiResponse::success(new LookupCategoryResource($lookupCategory->loadCount('values')));
    }

    public function update(UpdateLookupCategoryRequest $request, LookupCategory $lookupCategory): JsonResponse
    {
        $lookupCategory->update($request->validated());
        $this->cache->invalidateTenant($this->context->idOrFail());

        return ApiResponse::success(new LookupCategoryResource($lookupCategory));
    }

    public function destroy(LookupCategory $lookupCategory): JsonResponse
    {
        $this->authorize('delete', $lookupCategory);

        if ($lookupCategory->values()->exists()) {
            return ApiResponse::error('This category has values and cannot be deleted. Deactivate it instead.', 422);
        }

        $lookupCategory->delete();
        $this->cache->invalidateTenant($this->context->idOrFail());

        return ApiResponse::noContent();
    }
}
