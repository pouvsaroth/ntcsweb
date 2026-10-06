<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AttendanceCorrectionResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceCorrection;
use App\Models\Staff;
use App\Services\StaffAttendance\AttendanceCorrectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own attendance corrections — identity-gated (their own
 * staff record), same as MyOvertimeRequestController.
 */
final class MyAttendanceCorrectionController extends Controller
{
    public function __construct(private readonly AttendanceCorrectionService $corrections) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        return ApiResponse::success(AttendanceCorrectionResource::collection(
            AttendanceCorrection::query()->with('decidedBy')->where('staff_id', $staff->id)->orderByDesc('date')->limit(100)->get(),
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'check_in' => ['nullable', 'date_format:H:i'],
            'check_out' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $correction = $this->corrections->submit($staff, $data['date'], $data['check_in'] ?? null, $data['check_out'] ?? null, $data['reason'], $request->user());

        return ApiResponse::created(new AttendanceCorrectionResource($correction));
    }

    private function staffOrFail(Request $request): Staff
    {
        $staff = $request->user()?->staff;
        abort_if($staff === null, 403, 'Only staff request corrections.');

        return $staff;
    }
}
