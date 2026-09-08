<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreBuildingRequest;
use App\Http\Requests\Api\V1\Admin\UpdateBuildingRequest;
use App\Http\Resources\BuildingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Building;
use App\Models\Classroom;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BuildingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Building::class);

        $buildings = ApiQuery::for(Building::query(), $request)
            ->searchable('name', 'code', 'address')
            ->filterable(['status'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        $this->attachClassroomCounts($buildings);

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

        $this->attachClassroomCounts([$building]);

        return ApiResponse::success(new BuildingResource($building));
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

    /**
     * `buildings` lives in the tenant database while `classrooms` is still
     * central, so `classrooms_count` can no longer come from
     * withCount()/loadCount() (a single cross-database subquery) — it's
     * resolved as a separate query and attached manually, the shape
     * BuildingResource expects via whenCounted().
     *
     * @param  iterable<Building>  $buildings
     */
    private function attachClassroomCounts(iterable $buildings): void
    {
        // Not collect($buildings)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $buildings instanceof \Illuminate\Contracts\Pagination\Paginator ? $buildings->items() : (is_array($buildings) ? $buildings : iterator_to_array($buildings));

        if ($models === []) {
            return;
        }

        $counts = Classroom::query()
            ->whereIn('building_id', collect($models)->pluck('id'))
            ->select('building_id', DB::raw('COUNT(*) as total'))
            ->groupBy('building_id')
            ->pluck('total', 'building_id');

        foreach ($models as $building) {
            $building->setAttribute('classrooms_count', (int) ($counts[$building->id] ?? 0));
        }
    }
}
