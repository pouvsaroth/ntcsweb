<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectApprovalRequestRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalRequestService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The approval queue — see the "Approvals" page under eApprovals. Submitting
 * and viewing one's own requests is a separate, identity-gated controller
 * (MyApprovalRequestController), same split as LeaveRequest.
 */
final class ApprovalRequestController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestService $approvals,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the
        // view permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', ApprovalRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(ApprovalRequest::class)), 403);

        $query = ApprovalRequest::query()->with(['template', 'requester', 'decidedBy']);

        // The Approvals queue lists a pending request of an item with an
        // approval flow only to the group it's waiting on (see ApprovalFlow).
        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, ApprovalRequest::class, $user, $canViewAll);
        }

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'form_template_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(ApprovalRequestResource::collection($requests));
    }

    public function show(ApprovalRequest $approvalRequest): JsonResponse
    {
        $this->authorize('view', $approvalRequest);

        return ApiResponse::success(new ApprovalRequestResource(
            $approvalRequest->load(['template', 'requester', 'decidedBy'])
        ));
    }

    public function approve(ApprovalRequest $approvalRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($approvalRequest, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $approvalRequest = $this->flow->approve(
            $approvalRequest,
            $request->user(),
            fn ($doc) => $this->approvals->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->approvals->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new ApprovalRequestResource($approvalRequest->load(['template', 'requester', 'decidedBy'])));
    }

    public function reject(RejectApprovalRequestRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $approvalRequest = $this->approvals->reject($approvalRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ApprovalRequestResource($approvalRequest->load(['template', 'requester', 'decidedBy'])));
    }
}
