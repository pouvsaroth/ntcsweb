<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Services\Academic\LeaveRequestService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequests,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the
        // view permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', LeaveRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(LeaveRequest::class)), 403);

        // 'attachments' matches show()'s own eager loads — without it here,
        // LeaveRequestResource's whenLoaded('attachments') has nothing to
        // resolve for every row in the list, so the Approval queue's detail
        // view (which reads straight off this list, not a per-row show()
        // call) never has anything to show.
        $query = LeaveRequest::query()->with(['student', 'staff', 'decidedBy', 'attachments']);

        // The Approvals queue lists a pending request of an item with an
        // approval flow only to the group it's waiting on (see ApprovalFlow).
        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, LeaveRequest::class, $user, $canViewAll);
        }

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id', 'staff_id'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(LeaveRequestResource::collection($requests));
    }

    public function show(LeaveRequest $leaveRequest): JsonResponse
    {
        $this->authorize('view', $leaveRequest);

        return ApiResponse::success(new LeaveRequestResource(
            $leaveRequest->load(['student', 'staff', 'decidedBy', 'attachments'])
        ));
    }

    public function approve(LeaveRequest $leaveRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($leaveRequest, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $leaveRequest = $this->flow->approve(
            $leaveRequest,
            $request->user(),
            fn ($doc) => $this->leaveRequests->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->leaveRequests->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new LeaveRequestResource($leaveRequest->load(['student', 'staff', 'decidedBy'])));
    }

    public function reject(RejectLeaveRequestRequest $request, LeaveRequest $leaveRequest): JsonResponse
    {
        $leaveRequest = $this->leaveRequests->reject($leaveRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new LeaveRequestResource($leaveRequest->load(['student', 'staff', 'decidedBy'])));
    }
}
