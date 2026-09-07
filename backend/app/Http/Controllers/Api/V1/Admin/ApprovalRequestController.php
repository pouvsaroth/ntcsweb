<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectApprovalRequestRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalRequestService;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ApprovalRequest::class);

        $query = ApprovalRequest::query()->with(['template', 'requester', 'decidedBy']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'form_template_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

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
        $this->authorize('approve', $approvalRequest);

        $approvalRequest = $this->approvals->approve($approvalRequest, $request->user());

        return ApiResponse::success(new ApprovalRequestResource($approvalRequest->load(['template', 'requester', 'decidedBy'])));
    }

    public function reject(RejectApprovalRequestRequest $request, ApprovalRequest $approvalRequest): JsonResponse
    {
        $approvalRequest = $this->approvals->reject($approvalRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ApprovalRequestResource($approvalRequest->load(['template', 'requester', 'decidedBy'])));
    }
}
