<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectOvertimeRequestRequest;
use App\Http\Resources\OvertimeRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\OvertimeRequest;
use App\Models\Staff;
use App\Services\Approvals\ApprovalFlow;
use App\Services\StaffAttendance\OvertimeRequestService;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Attendance & Time > Overtime requests, and their decision in
 * E-Approvals > Approvals (same queue rules as ManpowerRequestController).
 */
final class OvertimeRequestController extends Controller
{
    private const RELATIONS = ['staff', 'requestedBy', 'decidedBy'];

    public function __construct(
        private readonly OvertimeRequestService $overtime,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAll = $user->can('viewAny', OvertimeRequest::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(OvertimeRequest::class)), 403);

        $query = OvertimeRequest::query()->with(self::RELATIONS);

        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, OvertimeRequest::class, $user, $canViewAll);
        }
        if ($request->filled('from')) {
            $query->whereDate('date', '>=', $request->date('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('date', '<=', $request->date('to'));
        }
        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->whereHas('staff', fn (Builder $s) => $s->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])->orWhereRaw('LOWER(employee_code) LIKE ?', [$term]));
        }

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status', 'staff_id'])
            ->sortable(['date', 'created_at'], default: '-date')
            ->paginate();

        $this->flow->attachProgress($requests->getCollection(), $user);

        return ApiResponse::success(OvertimeRequestResource::collection($requests));
    }

    /** HR files a request for a staff member. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', OvertimeRequest::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:960'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $overtime = $this->overtime->submit(Staff::query()->findOrFail($data['staff_id']), $data['date'], (int) $data['minutes'], $data['reason'], $request->user());

        return ApiResponse::created(new OvertimeRequestResource($overtime->load(self::RELATIONS)));
    }

    public function show(OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('view', $overtimeRequest);

        return ApiResponse::success(new OvertimeRequestResource($overtimeRequest->load(self::RELATIONS)));
    }

    public function destroy(OvertimeRequest $overtimeRequest): JsonResponse
    {
        $this->authorize('delete', $overtimeRequest);

        $overtimeRequest->delete();

        return ApiResponse::noContent();
    }

    public function approve(OvertimeRequest $overtimeRequest, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($overtimeRequest, $request->user(), 'approve');

        $overtimeRequest = $this->flow->approve(
            $overtimeRequest,
            $request->user(),
            fn ($doc) => $this->overtime->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->overtime->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new OvertimeRequestResource($overtimeRequest->load(self::RELATIONS)));
    }

    public function reject(RejectOvertimeRequestRequest $request, OvertimeRequest $overtimeRequest): JsonResponse
    {
        $overtimeRequest = $this->overtime->reject($overtimeRequest, $request->validated('reason'), $request->user());

        return ApiResponse::success(new OvertimeRequestResource($overtimeRequest->load(self::RELATIONS)));
    }
}
