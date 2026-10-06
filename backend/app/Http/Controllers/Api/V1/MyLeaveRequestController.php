<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Models\Staff;
use App\Models\Student;
use App\Services\Academic\LeaveRequestService;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Self-service: "my leave requests," not "all leave requests." Identity-
 * gated through `$user->student` OR `$user->staff` — either a student or a
 * staff member may file one for themselves, the same pattern as
 * MyAttendanceController — no permission is required or checked here.
 */
final class MyLeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequests,
        private readonly LeaveBalanceService $balances,
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$student, $staff] = $this->requesterOrFail($request);

        $query = LeaveRequest::query()
            ->when($student !== null, fn ($q) => $q->where('student_id', $student->id))
            ->when($staff !== null, fn ($q) => $q->where('staff_id', $staff->id))
            ->with(['attachments', 'leaveType']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(LeaveRequestResource::collection($requests));
    }

    public function store(StoreMyLeaveRequestRequest $request): JsonResponse
    {
        [$student, $staff] = $this->requesterOrFail($request);

        $leaveRequest = $this->leaveRequests->submit($student, $staff, [
            ...$request->validated(),
            'attachments' => $request->file('attachments', []),
        ]);

        return ApiResponse::created(new LeaveRequestResource($leaveRequest));
    }

    /**
     * A staff member's leave types for the request form — each active type
     * they may take, with the year's balance (null = not limited). A
     * student, or a school with no leave types yet, gets an empty list.
     */
    public function types(Request $request): JsonResponse
    {
        [, $staff] = $this->requesterOrFail($request);
        $year = $request->integer('year', (int) now()->year);

        $types = $staff === null ? collect() : $this->balances->typesFor($staff, $year);

        return ApiResponse::success($types->map(fn (array $row) => [
            'id' => $row['type']->id,
            'code' => $row['type']->code,
            'name' => $row['type']->name,
            'color' => $row['type']->color,
            'allow_half_day' => $row['type']->allow_half_day,
            'requires_attachment' => $row['type']->requires_attachment,
            'balance' => $row['balance'],
        ])->values());
    }

    /** How many working days these dates take for the signed-in staff member — shown on the form as they pick. */
    public function quote(Request $request): JsonResponse
    {
        [, $staff] = $this->requesterOrFail($request);
        abort_if($staff === null, 404);

        $data = $request->validate([
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'day_part' => ['nullable', Rule::in(LeaveRequest::DAY_PARTS)],
        ]);

        $from = CarbonImmutable::parse($data['from_date']);
        $to = CarbonImmutable::parse($data['to_date']);
        abort_if($from->diffInDays($to) > 366, 422, 'Pick a shorter range.');

        return ApiResponse::success(['days' => $this->balances->countDays($staff, $from, $to, $data['day_part'] ?? LeaveRequest::DAY_FULL)]);
    }

    /**
     * A student takes priority if an account is somehow linked to both — in
     * practice a user is one or the other, never both.
     *
     * @return array{0: Student|null, 1: Staff|null}
     */
    private function requesterOrFail(Request $request): array
    {
        $student = $request->user()?->student;
        $staff = $student === null ? $request->user()?->staff : null;

        if ($student === null && $staff === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student or staff record.',
            ]);
        }

        return [$student, $staff];
    }
}
