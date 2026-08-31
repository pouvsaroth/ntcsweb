<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
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

        $query = AttendanceRecord::query()->where('student_id', $student->id)->with('schoolClass');

        $records = ApiQuery::for($query, $request)
            ->filterable(['class_id', 'status'])
            ->sortable(['date'], default: '-date')
            ->paginate();

        return ApiResponse::success(AttendanceRecordResource::collection($records));
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
