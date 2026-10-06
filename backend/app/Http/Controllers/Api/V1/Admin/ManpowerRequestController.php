<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ManpowerRequestRequest;
use App\Http\Requests\Api\V1\Admin\RejectManpowerRequestRequest;
use App\Http\Resources\ManpowerRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ManpowerRequest;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Recruitment\ManpowerRequestService;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Recruitment > Manpower request, and its decision in E-Approvals >
 * Approvals (same queue rules as ResignationRequestController).
 */
final class ManpowerRequestController extends Controller
{
    private const RELATIONS = ['department', 'position', 'requestedBy', 'decidedBy'];

    public function __construct(
        private readonly ManpowerRequestService $manpowerRequests,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the view
        // permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', ManpowerRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(ManpowerRequest::class)), 403);

        $query = ManpowerRequest::query()->with(self::RELATIONS)->withCount('jobPositions');

        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, ManpowerRequest::class, $user, $canViewAll);
        }

        $requests = ApiQuery::for($query, $request)
            ->searchable('job_title')
            ->filterable(['status', 'department_id'])
            ->sortable(['needed_by', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(ManpowerRequestResource::collection($requests));
    }

    public function store(ManpowerRequestRequest $request): JsonResponse
    {
        $this->authorize('create', ManpowerRequest::class);

        $manpowerRequest = $this->manpowerRequests->submit($request->user(), $request->validated());

        return ApiResponse::created(new ManpowerRequestResource($manpowerRequest->load(self::RELATIONS)));
    }

    public function show(ManpowerRequest $manpowerRequest): JsonResponse
    {
        $this->authorize('view', $manpowerRequest);

        return ApiResponse::success(new ManpowerRequestResource($manpowerRequest->load(self::RELATIONS)));
    }

    public function update(ManpowerRequestRequest $request, ManpowerRequest $manpowerRequest): JsonResponse
    {
        $this->authorize('update', $manpowerRequest);

        $manpowerRequest->update($request->validated());

        return ApiResponse::success(new ManpowerRequestResource($manpowerRequest->load(self::RELATIONS)));
    }

    public function destroy(ManpowerRequest $manpowerRequest): JsonResponse
    {
        $this->authorize('delete', $manpowerRequest);

        $manpowerRequest->delete();

        return ApiResponse::noContent();
    }

    public function approve(ManpowerRequest $manpowerRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($manpowerRequest, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $manpowerRequest = $this->flow->approve(
            $manpowerRequest,
            $request->user(),
            fn ($doc) => $this->manpowerRequests->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->manpowerRequests->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new ManpowerRequestResource($manpowerRequest->load(self::RELATIONS)));
    }

    public function reject(RejectManpowerRequestRequest $request, ManpowerRequest $manpowerRequest): JsonResponse
    {
        $manpowerRequest = $this->manpowerRequests->reject($manpowerRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ManpowerRequestResource($manpowerRequest->load(self::RELATIONS)));
    }
}
