<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreTeacherRequest;
use App\Http\Requests\Api\V1\Admin\UpdateTeacherRequest;
use App\Http\Resources\TeacherResource;
use App\Http\Responses\ApiResponse;
use App\Models\Teacher;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TeacherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Teacher::class);

        $teachers = ApiQuery::for(Teacher::query()->withCount('classes'), $request)
            ->searchable('name', 'employee_code', 'email')
            ->filterable(['status'])
            ->sortable(['name', 'employee_code', 'hire_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(TeacherResource::collection($teachers));
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        $teacher = Teacher::query()->create($request->validated());

        return ApiResponse::created(new TeacherResource($teacher));
    }

    public function show(Teacher $teacher): JsonResponse
    {
        $this->authorize('view', $teacher);

        return ApiResponse::success(new TeacherResource($teacher->loadCount('classes')));
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): JsonResponse
    {
        $teacher->update($request->validated());

        return ApiResponse::success(new TeacherResource($teacher));
    }

    public function destroy(Teacher $teacher): JsonResponse
    {
        $this->authorize('delete', $teacher);

        $teacher->delete();

        return ApiResponse::noContent();
    }
}
