<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectResignationRequestRequest;
use App\Http\Resources\ResignationRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ResignationRequest;
use App\Services\Academic\ResignationRequestService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ResignationRequestController extends Controller
{
    public function __construct(
        private readonly ResignationRequestService $resignationRequests,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the
        // view permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', ResignationRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(ResignationRequest::class)), 403);

        $query = ResignationRequest::query()->with(['staff.position', 'decidedBy']);

        // The Approvals queue lists a pending request of an item with an
        // approval flow only to the group it's waiting on (see ApprovalFlow).
        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, ResignationRequest::class, $user, $canViewAll);
        }

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'staff_id'])
            ->sortable(['resignation_date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(ResignationRequestResource::collection($requests));
    }

    public function show(ResignationRequest $resignationRequest): JsonResponse
    {
        $this->authorize('view', $resignationRequest);

        return ApiResponse::success(new ResignationRequestResource(
            $resignationRequest->load(['staff.position', 'decidedBy'])
        ));
    }

    public function approve(ResignationRequest $resignationRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($resignationRequest, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $resignationRequest = $this->flow->approve(
            $resignationRequest,
            $request->user(),
            fn ($doc) => $this->resignationRequests->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->resignationRequests->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new ResignationRequestResource($resignationRequest->load(['staff.position', 'decidedBy'])));
    }

    public function reject(RejectResignationRequestRequest $request, ResignationRequest $resignationRequest): JsonResponse
    {
        $resignationRequest = $this->resignationRequests->reject($resignationRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ResignationRequestResource($resignationRequest->load(['staff.position', 'decidedBy'])));
    }
}
