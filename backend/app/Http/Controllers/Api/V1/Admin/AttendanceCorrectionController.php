<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectAttendanceCorrectionRequest;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Services\Approvals\ApprovalFlow;
use App\Services\StaffAttendance\AttendanceCorrectionService;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Attendance & Time > Attendance correction, and its decision in
 * E-Approvals > Approvals (same queue rules as OvertimeRequestController).
 */
final class AttendanceCorrectionController extends Controller
{
    private const RELATIONS = ['staff', 'requestedBy', 'decidedBy'];

    public function __construct(
        private readonly AttendanceCorrectionService $corrections,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewAll = $user->can('viewAny', AttendanceCorrection::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(AttendanceCorrection::class)), 403);

        $query = AttendanceCorrection::query()->with(self::RELATIONS);

        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, AttendanceCorrection::class, $user, $canViewAll);
        }
        if ($request->filled('search')) {
            $term = '%'.mb_strtolower((string) $request->query('search')).'%';
            $query->whereHas('staff', fn (Builder $s) => $s->whereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$term])->orWhereRaw('LOWER(employee_code) LIKE ?', [$term]));
        }

        $corrections = ApiQuery::for($query, $request)
            ->filterable(['status', 'staff_id'])
            ->sortable(['date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($corrections->getCollection(), $user);

        return ApiResponse::success(AttendanceCorrectionResource::collection($corrections));
    }

    /** HR files a correction for a staff member. */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AttendanceCorrection::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
            'date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $correction = $this->corrections->submit(
            Staff::query()->findOrFail($data['staff_id']), $data['date'], $data['check_in'] ?? null, $data['check_out'] ?? null, $data['reason'], $request->user(),
        );

        return ApiResponse::created(new AttendanceCorrectionResource($correction->load(self::RELATIONS)));
    }

    public function show(AttendanceCorrection $attendanceCorrection): JsonResponse
    {
        $this->authorize('view', $attendanceCorrection);

        return ApiResponse::success(new AttendanceCorrectionResource($attendanceCorrection->load(self::RELATIONS)));
    }

    public function destroy(AttendanceCorrection $attendanceCorrection): JsonResponse
    {
        $this->authorize('delete', $attendanceCorrection);

        $attendanceCorrection->delete();

        return ApiResponse::noContent();
    }

    public function approve(AttendanceCorrection $attendanceCorrection, Request $request): JsonResponse
    {
        $this->flow->authorizeDecision($attendanceCorrection, $request->user(), 'approve');

        $attendanceCorrection = $this->flow->approve(
            $attendanceCorrection,
            $request->user(),
            fn ($doc) => $this->corrections->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->corrections->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new AttendanceCorrectionResource($attendanceCorrection->load(self::RELATIONS)));
    }

    public function reject(RejectAttendanceCorrectionRequest $request, AttendanceCorrection $attendanceCorrection): JsonResponse
    {
        $attendanceCorrection = $this->corrections->reject($attendanceCorrection, $request->validated('reason'), $request->user());

        return ApiResponse::success(new AttendanceCorrectionResource($attendanceCorrection->load(self::RELATIONS)));
    }
}
