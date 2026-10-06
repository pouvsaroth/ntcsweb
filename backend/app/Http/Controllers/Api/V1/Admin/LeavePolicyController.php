<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\LeavePolicyRequest;
use App\Http\Resources\LeavePolicyResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeavePolicy;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HRM > Leave Management > Leave policies. */
final class LeavePolicyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeavePolicy::class);

        $policies = ApiQuery::for(LeavePolicy::query()->with(['leaveType', 'jobGrade']), $request)
            ->searchable('name')
            ->filterable(['leave_type_id', 'job_grade_id', 'is_active'])
            ->sortable(['name', 'leave_type_id', 'days_per_year'], default: 'leave_type_id')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(LeavePolicyResource::collection($policies));
    }

    public function store(LeavePolicyRequest $request): JsonResponse
    {
        $this->authorize('create', LeavePolicy::class);

        $policy = LeavePolicy::query()->create($request->validated());

        return ApiResponse::created(new LeavePolicyResource($policy->load(['leaveType', 'jobGrade'])));
    }

    public function show(LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorize('view', $leavePolicy);

        return ApiResponse::success(new LeavePolicyResource($leavePolicy->load(['leaveType', 'jobGrade'])));
    }

    public function update(LeavePolicyRequest $request, LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorize('update', $leavePolicy);

        $leavePolicy->update($request->validated());

        return ApiResponse::success(new LeavePolicyResource($leavePolicy->load(['leaveType', 'jobGrade'])));
    }

    public function destroy(LeavePolicy $leavePolicy): JsonResponse
    {
        $this->authorize('delete', $leavePolicy);

        $leavePolicy->delete();

        return ApiResponse::noContent();
    }
}
