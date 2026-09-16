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
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
    ) {}

    public function index(Request $request): JsonResponse
    {
        [$student, $staff] = $this->requesterOrFail($request);

        $query = LeaveRequest::query()
            ->when($student !== null, fn ($q) => $q->where('student_id', $student->id))
            ->when($staff !== null, fn ($q) => $q->where('staff_id', $staff->id))
            ->with('attachments');

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
