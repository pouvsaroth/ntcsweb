<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
use App\Services\Academic\AttendanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my attendance," not "all attendance." Identity-
 * gated through `$user->student`, the same pattern as MyInvoiceController —
 * no `attendance.view` permission is required or checked here.
 */
final class MyAttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = AttendanceRecord::query()->where('student_id', $student->id)->with('schoolClass.schedules');

        $records = ApiQuery::for($query, $request)
            ->filterable(['class_id', 'status'])
            ->sortable(['date'], default: '-date')
            ->paginate();

        return ApiResponse::success(AttendanceRecordResource::collection($records));
    }

    /**
     * The student home screen's hour cards — one row per course (active
     * enrollment) the student is studying, over the whole enrollment so far:
     * absent hours (permission + absent sessions, plus the minutes actually
     * missed on a late day — not the whole late session), approved make-up
     * hours, and what's left to make up. Session hours come from
     * AttendanceService::summarize(), so they match the admin Attendance
     * Summary.
     */
    public function hoursSummary(Request $request, AttendanceService $attendance): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $rows = $attendance->summarize(null, null, null, $student->id);

        return ApiResponse::success(array_map(function (array $row) {
            $absent = $row['permission_hours'] + $row['absent_hours'] + $row['late_minutes'] / 60;
            $madeUp = $row['make_up_hours'];

            return [
                'enrollment_id' => $row['enrollment_id'],
                'course_package' => $row['course_package'],
                'school_class' => $row['school_class'],
                'absent_hours' => round($absent, 1),
                'make_up_hours' => round($madeUp, 1),
                'remaining_hours' => round(max($absent - $madeUp, 0), 1),
            ];
        }, $rows));
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
