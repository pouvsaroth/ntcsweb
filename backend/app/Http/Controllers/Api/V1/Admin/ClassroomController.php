<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreClassroomRequest;
use App\Http\Requests\Api\V1\Admin\UpdateClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Http\Responses\ApiResponse;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ClassroomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Classroom::class);

        $classrooms = ApiQuery::for(Classroom::query()->with('building'), $request)
            ->searchable('name', 'code', 'location')
            ->filterable(['status', 'building_id'])
            ->sortable(['name', 'capacity', 'created_at'], default: 'name')
            ->paginate();

        $this->attachClassesCounts($classrooms);

        return ApiResponse::success(ClassroomResource::collection($classrooms));
    }

    public function store(StoreClassroomRequest $request): JsonResponse
    {
        $classroom = Classroom::query()->create($request->validated());

        return ApiResponse::created(new ClassroomResource($classroom->load('building')));
    }

    public function show(Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $classroom->load('building');
        $this->attachClassesCounts([$classroom]);

        return ApiResponse::success(new ClassroomResource($classroom));
    }

    public function update(UpdateClassroomRequest $request, Classroom $classroom): JsonResponse
    {
        $classroom->update($request->validated());

        return ApiResponse::success(new ClassroomResource($classroom->load('building')));
    }

    public function destroy(Classroom $classroom): JsonResponse
    {
        $this->authorize('delete', $classroom);

        $classroom->delete();

        return ApiResponse::noContent();
    }

    /**
     * `classrooms` lives in the tenant database while `classes` is still
     * central, so `classes_count` can no longer come from
     * withCount()/loadCount() (a single cross-database subquery) — it's
     * resolved as a separate query and attached manually, the shape
     * ClassroomResource expects via whenCounted().
     *
     * @param  iterable<Classroom>  $classrooms
     */
    private function attachClassesCounts(iterable $classrooms): void
    {
        // Not collect($classrooms)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $classrooms instanceof \Illuminate\Contracts\Pagination\Paginator ? $classrooms->items() : (is_array($classrooms) ? $classrooms : iterator_to_array($classrooms));

        if ($models === []) {
            return;
        }

        $counts = SchoolClass::query()
            ->whereIn('classroom_id', collect($models)->pluck('id'))
            ->select('classroom_id', DB::raw('COUNT(*) as total'))
            ->groupBy('classroom_id')
            ->pluck('total', 'classroom_id');

        foreach ($models as $classroom) {
            $classroom->setAttribute('classes_count', (int) ($counts[$classroom->id] ?? 0));
        }
    }
}
