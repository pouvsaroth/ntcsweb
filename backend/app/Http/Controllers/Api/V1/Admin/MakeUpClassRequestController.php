<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectMakeUpClassRequestRequest;
use App\Http\Resources\MakeUpClassRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MakeUpClassRequest;
use App\Services\Academic\MakeUpClassRequestService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MakeUpClassRequestController extends Controller
{
    public function __construct(
        private readonly MakeUpClassRequestService $makeUpClassRequests,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the
        // view permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', MakeUpClassRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(MakeUpClassRequest::class)), 403);

        $query = MakeUpClassRequest::query()->with(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy']);

        // The Approvals queue lists a pending request of an item with an
        // approval flow only to the group it's waiting on (see ApprovalFlow).
        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, MakeUpClassRequest::class, $user, $canViewAll);
        }

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(MakeUpClassRequestResource::collection($requests));
    }

    public function show(MakeUpClassRequest $makeUpClassRequest): JsonResponse
    {
        $this->authorize('view', $makeUpClassRequest);

        return ApiResponse::success(new MakeUpClassRequestResource(
            $makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])
        ));
    }

    public function approve(MakeUpClassRequest $makeUpClassRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($makeUpClassRequest, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $makeUpClassRequest = $this->flow->approve(
            $makeUpClassRequest,
            $request->user(),
            fn ($doc) => $this->makeUpClassRequests->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->makeUpClassRequests->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new MakeUpClassRequestResource($makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])));
    }

    public function reject(RejectMakeUpClassRequestRequest $request, MakeUpClassRequest $makeUpClassRequest): JsonResponse
    {
        $makeUpClassRequest = $this->makeUpClassRequests->reject($makeUpClassRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new MakeUpClassRequestResource($makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass', 'decidedBy'])));
    }
}
