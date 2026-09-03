<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreClassroomTableRequest;
use App\Http\Requests\Api\V1\Admin\UpdateClassroomTableRequest;
use App\Http\Resources\ClassroomTableResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClassroomTableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClassroomTable::class);

        $tables = ApiQuery::for(ClassroomTable::query()->with('classroom'), $request)
            ->filterable(['classroom_id'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(ClassroomTableResource::collection($tables));
    }

    public function store(StoreClassroomTableRequest $request): JsonResponse
    {
        $table = ClassroomTable::query()->create($request->validated());

        return ApiResponse::created(new ClassroomTableResource($table->load('classroom')));
    }

    public function show(ClassroomTable $classroomTable): JsonResponse
    {
        $this->authorize('view', $classroomTable);

        return ApiResponse::success(new ClassroomTableResource($classroomTable->load('classroom')));
    }

    public function update(UpdateClassroomTableRequest $request, ClassroomTable $classroomTable): JsonResponse
    {
        $classroomTable->update($request->validated());

        return ApiResponse::success(new ClassroomTableResource($classroomTable->load('classroom')));
    }

    public function destroy(ClassroomTable $classroomTable): JsonResponse
    {
        $this->authorize('delete', $classroomTable);

        if ($classroomTable->enrollments()->where('status', '!=', Enrollment::STATUS_DROPPED)->exists()) {
            return ApiResponse::error('This table has a student seated at it and cannot be deleted.', 422);
        }

        $classroomTable->delete();

        return ApiResponse::noContent();
    }
}
