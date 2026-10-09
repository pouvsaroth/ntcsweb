<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffAttendanceResource;
use App\Http\Responses\ApiResponse;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\WorkSchedule;
use App\Services\StaffAttendance\StaffAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A staff member's own check-in / check-out and attendance — identity-
 * gated (their own staff record), no permission needed, same as
 * MyLeaveRequestController.
 */
final class MyStaffAttendanceController extends Controller
{
    public function __construct(private readonly StaffAttendanceService $attendance) {}

    /** Today: the shift, and whether they've checked in/out. */
    public function today(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);
        $now = $this->attendance->localNow();

        $open = StaffAttendance::query()->with('shift')->where('staff_id', $staff->id)->whereNotNull('check_in_at')->whereNull('check_out_at')
            ->where('check_in_at', '>=', $now->subHours(20))->orderByDesc('check_in_at')->first();
        $today = StaffAttendance::query()->with('shift')->where('staff_id', $staff->id)->whereDate('date', $now->toDateString())->first();
        $shiftId = WorkSchedule::forStaff($staff)?->shiftIdOn($now);
        $shift = $shiftId !== null ? Shift::query()->find($shiftId) : null;
        // Today's own hours — see Shift::timesOn().
        [$shiftStart, $shiftEnd] = $shift?->timesOn($now) ?? [null, null];

        return ApiResponse::success([
            'now' => $now->toIso8601String(),
            'shift' => $shift !== null ? ['name' => $shift->name, 'start_time' => $shiftStart, 'end_time' => $shiftEnd] : null,
            'record' => ($open ?? $today) !== null ? new StaffAttendanceResource($open ?? $today) : null,
            'can_check_in' => $open === null && $today?->check_in_at === null,
            'can_check_out' => $open !== null,
        ]);
    }

    /** Their own month (YYYY-MM), day by day. */
    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOrFail($request);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? $this->attendance->localNow()->format('Y-m');
        $from = CarbonImmutable::parse("{$month}-01", $this->attendance->timezone());

        // An Eloquent collection — sheet() reads modelKeys(), which a plain collect() doesn't have.
        $sheet = $this->attendance->sheet($staff->newCollection([$staff]), $from, $from->endOfMonth()->startOfDay());

        return ApiResponse::success(['month' => $month, 'days' => $sheet[0]['days'], 'totals' => $sheet[0]['totals']]);
    }

    public function checkIn(Request $request): JsonResponse
    {
        [$lat, $lng] = $this->location($request);

        return ApiResponse::success(new StaffAttendanceResource($this->attendance->checkIn($this->staffOrFail($request), $lat, $lng)->load('shift')));
    }

    public function checkOut(Request $request): JsonResponse
    {
        [$lat, $lng] = $this->location($request);

        return ApiResponse::success(new StaffAttendanceResource($this->attendance->checkOut($this->staffOrFail($request), $lat, $lng)->load('shift')));
    }

    /** @return array{0: float|null, 1: float|null} */
    private function location(Request $request): array
    {
        $data = $request->validate([
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        return [isset($data['latitude']) ? (float) $data['latitude'] : null, isset($data['longitude']) ? (float) $data['longitude'] : null];
    }

    private function staffOrFail(Request $request): Staff
    {
        $staff = $request->user()?->staff;
        abort_if($staff === null, 403, 'Only staff check in.');

        return $staff;
    }
}
