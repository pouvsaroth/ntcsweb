<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBuildingRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Building;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BuildingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Building::class);

        $buildings = ApiQuery::for(Building::query()->withCount('classrooms'), $request)
            ->searchable('name', 'code', 'address')
            ->filterable(['status'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(BuildingResource::collection($buildings));
    }

    public function store(StoreBuildingRequest $request): JsonResponse
    {
        $building = Building::query()->create($request->validated());

        return ApiResponse::created(new BuildingResource($building));
    }

    public function show(Building $building): JsonResponse
    {
        $this->authorize('view', $building);

        return ApiResponse::success(new BuildingResource($building->loadCount('classrooms')));
    }

    public function update(UpdateBuildingRequest $request, Building $building): JsonResponse
    {
        $building->update($request->validated());

        return ApiResponse::success(new BuildingResource($building));
    }

    public function destroy(Building $building): JsonResponse
    {
        $this->authorize('delete', $building);

        $building->delete();

        return ApiResponse::noContent();
    }
}
