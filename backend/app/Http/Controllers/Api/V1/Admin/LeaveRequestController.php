<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Services\Academic\LeaveRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LeaveRequest::class);

        $query = LeaveRequest::query()->with(['student', 'decidedBy']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(LeaveRequestResource::collection($requests));
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        return ApiResponse::success(new LeaveRequestResource(
            $leaveRequest->load(['student', 'decidedBy', 'attachments'])
        ));
    }

    public function approve(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $this->authorize('approve', $leaveRequest);

        $leaveRequest = $this->leaveRequests->approve($leaveRequest, $request->user());

        return ApiResponse::success(new LeaveRequestResource($leaveRequest->load(['student', 'decidedBy'])));
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leaveRequest = $this->leaveRequests->reject($leaveRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new LeaveRequestResource($leaveRequest->load(['student', 'decidedBy'])));
    }
}
