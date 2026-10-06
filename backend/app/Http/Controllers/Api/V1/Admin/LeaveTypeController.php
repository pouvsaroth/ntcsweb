<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\LeaveTypeRequest;
use App\Http\Resources\LeaveTypeResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HRM > Leave Management > Leave types. */
final class LeaveTypeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveType::class);

        $types = ApiQuery::for(LeaveType::query()->withCount('policies'), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['code', 'name'], default: 'code')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(LeaveTypeResource::collection($types));
    }

    public function store(LeaveTypeRequest $request): JsonResponse
    {
        $this->authorize('create', LeaveType::class);

        return ApiResponse::created(new LeaveTypeResource(LeaveType::query()->create($request->validated())));
    }

    public function show(LeaveType $leaveType): JsonResponse
    {
        $this->authorize('view', $leaveType);

        return ApiResponse::success(new LeaveTypeResource($leaveType->loadCount('policies')));
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): JsonResponse
    {
        $this->authorize('update', $leaveType);

        $leaveType->update($request->validated());

        return ApiResponse::success(new LeaveTypeResource($leaveType->loadCount('policies')));
    }

    public function destroy(LeaveType $leaveType): JsonResponse
    {
        $this->authorize('delete', $leaveType);

        if ($leaveType->policies()->exists()) {
            return ApiResponse::error('This leave type has policies and cannot be deleted. Delete its policies first, or deactivate it instead.', 422);
        }

        if (LeaveRequest::query()->withTrashed()->where('leave_type_id', $leaveType->id)->exists()) {
            return ApiResponse::error('This leave type has leave requests and cannot be deleted. Deactivate it instead.', 422);
        }

        $leaveType->delete();

        return ApiResponse::noContent();
    }
}
