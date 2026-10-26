<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ClassChangeRequest;
use App\Services\Academic\ClassChangeRequestService;
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Students' class change requests in eApprovals > Approvals. Listed to
 * holders of `class-change-requests.view` (and, with an Approval Flow, to the
 * group whose turn it is); decided by the flow, else the approve / reject
 * permissions. Approving moves the student (ClassChangeRequestService).
 */
final class ClassChangeRequestController extends Controller
{
    public function __construct(
        private readonly ClassChangeRequestService $requests,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAll = $user->hasPermission(Permissions::CLASS_CHANGE_REQUESTS_VIEW);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, [DocumentType::CLASS_CHANGE]), 403);
        $data = $request->validate(['status' => ['nullable', Rule::in(['pending', 'approved', 'rejected'])]]);

        $query = ClassChangeRequest::query()->latest('id')->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s));
        $this->flow->scopeQueue($query, ClassChangeRequest::class, $user, $canViewAll);
        $rows = $query->limit(300)->get();
        $this->flow->attachProgress($rows, $user);

        return ApiResponse::success($rows->map(fn (ClassChangeRequest $r) => $this->requests->present($r, $user))->values());
    }

    public function approve(Request $request, ClassChangeRequest $classChangeRequest): JsonResponse
    {
        $done = $this->requests->approve($classChangeRequest, $request->user());

        return ApiResponse::success($this->requests->present($done->refresh(), $request->user()));
    }

    public function reject(Request $request, ClassChangeRequest $classChangeRequest): JsonResponse
    {
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return ApiResponse::success($this->requests->present($this->requests->reject($classChangeRequest, $reason, $request->user()), $request->user()));
    }
}
