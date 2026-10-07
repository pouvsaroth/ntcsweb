<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyApprovalRequestRequest;
use App\Http\Resources\ApprovalRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\ApprovalRequest;
use App\Models\FormTemplate;
use App\Services\Approvals\ApprovalRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-service: "my approval requests," not "the whole queue." Identity-
 * gated — any signed-in user, same pattern as MyLeaveRequestController — no
 * permission is required or checked here.
 */
final class MyApprovalRequestController extends Controller
{
    public function __construct(
        private readonly ApprovalRequestService $approvals,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = ApprovalRequest::query()
            ->where('requested_by', $request->user()->getKey())
            ->with('template');

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ApprovalRequestResource::collection($requests));
    }

    public function store(StoreMyApprovalRequestRequest $request): JsonResponse
    {
        $template = FormTemplate::query()->findOrFail($request->validated('form_template_id'));

        $approvalRequest = $this->approvals->submit($template, $request->user(), $request->validated());

        return ApiResponse::created(new ApprovalRequestResource($approvalRequest->load('template')));
    }

    /**
     * The requester withdraws their own request while it is still waiting
     * (pending) — once any decision is made it can no longer be deleted.
     * Someone else's request 404s, same as if it didn't exist.
     */
    public function destroy(Request $request, int $approvalRequest): JsonResponse
    {
        DB::transaction(function () use ($request, $approvalRequest) {
            $row = ApprovalRequest::query()
                ->where('requested_by', $request->user()->getKey())
                ->whereKey($approvalRequest)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->status !== ApprovalRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only a request that is still waiting can be deleted.']);
            }

            $row->delete();
        });

        return ApiResponse::noContent();
    }
}
