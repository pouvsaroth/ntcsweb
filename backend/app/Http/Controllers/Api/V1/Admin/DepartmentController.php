<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreDepartmentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateDepartmentRequest;
use App\Http\Resources\DepartmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Department;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DepartmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        $departments = ApiQuery::for(Department::query(), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['name', 'code', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(DepartmentResource::collection($departments));
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::query()->create($request->validated());

        return ApiResponse::created(new DepartmentResource($department));
    }

    public function show(Department $department): JsonResponse
    {
        $this->authorize('view', $department);

        return ApiResponse::success(new DepartmentResource($department));
    }

    public function update(UpdateDepartmentRequest $request, Department $department): JsonResponse
    {
        $department->update($request->validated());

        return ApiResponse::success(new DepartmentResource($department));
    }

    public function destroy(Department $department): JsonResponse
    {
        $this->authorize('delete', $department);

        if ($department->assets()->exists()) {
            return ApiResponse::error('This department is in use and cannot be deleted. Deactivate it instead.', 422);
        }

        $department->delete();

        return ApiResponse::noContent();
    }
}
