<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyLeaveRequestRequest;
use App\Http\Resources\LeaveRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\LeaveRequest;
use App\Services\Academic\LeaveRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my leave requests," not "all leave requests."
 * Identity-gated through `$user->student`, the same pattern as
 * MyAttendanceController — no permission is required or checked here.
 */
final class MyLeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $leaveRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = LeaveRequest::query()->where('student_id', $student->id)->with('attachments');

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(LeaveRequestResource::collection($requests));
    }

    public function store(StoreMyLeaveRequestRequest $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $leaveRequest = $this->leaveRequests->submit($student, [
            ...$request->validated(),
            'attachments' => $request->file('attachments', []),
        ]);

        return ApiResponse::created(new LeaveRequestResource($leaveRequest));
    }

    private function studentOrFail(Request $request)
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        return $student;
    }
}
