<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\OvertimeRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\OvertimeRequest;
use App\Models\Staff;
use App\Services\StaffAttendance\OvertimeRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own overtime claims — identity-gated (their own staff
 * record), same as MyStaffAttendanceController.
 */
final class MyOvertimeRequestController extends Controller
{
    public function __construct(private readonly OvertimeRequestService $overtime) {}

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        $requests = OvertimeRequest::query()->with('decidedBy')->where('staff_id', $staff->id)->orderByDesc('date')->limit(100)->get();

        return ApiResponse::success(OvertimeRequestResource::collection($requests));
    }

    public function store(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);

        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'minutes' => ['required', 'integer', 'min:1', 'max:960'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        $overtime = $this->overtime->submit($staff, $data['date'], (int) $data['minutes'], $data['reason'], $request->user());

        return ApiResponse::created(new OvertimeRequestResource($overtime));
    }

    private function staffOrFail(Request $request): Staff
    {
        $staff = $request->user()?->staff;
        abort_if($staff === null, 403, 'Only staff claim overtime.');

        return $staff;
    }
}
