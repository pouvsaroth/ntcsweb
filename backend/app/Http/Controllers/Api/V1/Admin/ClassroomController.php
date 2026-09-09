<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreClassroomRequest;
use App\Http\Requests\Api\V1\Admin\UpdateClassroomRequest;
use App\Http\Resources\ClassroomResource;
use App\Http\Responses\ApiResponse;
use App\Models\Classroom;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassroomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Classroom::class);

        $classrooms = ApiQuery::for(Classroom::query()->with('building')->withCount('classes'), $request)
            ->searchable('name', 'code', 'location')
            ->filterable(['status', 'building_id'])
            ->sortable(['name', 'capacity', 'created_at'], default: 'name')
            ->paginate();

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

        return ApiResponse::success(new ClassroomResource($classroom->load('building')->loadCount('classes')));
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
}
