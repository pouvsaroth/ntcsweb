<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceApproval;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Services\StaffAttendance\StaffAttendanceService;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * HRM > Attendance & Time > Attendance approval: a month's totals per
 * staff member, and the manager's sign-off — which locks that month (see
 * StaffAttendanceService::assertOpen()). Unlocking deletes the sign-off.
 */
final class AttendanceApprovalController extends Controller
{
    public function __construct(
        private readonly StaffAttendanceService $attendance,
        private readonly StaffAttendanceController $staffAttendance,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffAttendance::class);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'department_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $from = CarbonImmutable::parse("{$data['month']}-01", $this->attendance->timezone());

        $staff = $this->staffAttendance->staffQuery($data)->get();
        $approvals = AttendanceApproval::query()->with('approvedBy')->whereIn('staff_id', $staff->modelKeys())->where('month', $data['month'])->get()->keyBy('staff_id');

        $rows = array_map(function (array $row) use ($approvals) {
            $approval = $approvals->get($row['staff']->id);

            return [
                'staff' => ['id' => $row['staff']->id, 'name' => $row['staff']->fullName(), 'employee_code' => $row['staff']->employee_code],
                'totals' => $row['totals'],
                // Days still open: checked in but never out — worth fixing before signing off.
                'incomplete' => $row['totals']['incomplete'],
                'approval' => $approval !== null ? [
                    'id' => $approval->id,
                    'approved_by' => $approval->approvedBy?->name,
                    'approved_at' => $approval->created_at?->toIso8601String(),
                    'note' => $approval->note,
                ] : null,
            ];
        }, $this->attendance->sheet($staff, $from, $from->endOfMonth()->startOfDay()));

        return ApiResponse::success(['month' => $data['month'], 'staff' => $rows]);
    }

    /** Signs off the month for the given staff members (already signed-off ones are left as they are). */
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STAFF_ATTENDANCE_APPROVE), 403);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m', 'before_or_equal:'.$this->attendance->localNow()->format('Y-m')],
            'staff_ids' => ['required', 'array', 'min:1', 'max:500'],
            'staff_ids.*' => ['integer', 'distinct', Rule::exists('tenant.staff', 'id')],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $created = 0;
        foreach ($data['staff_ids'] as $staffId) {
            $approval = AttendanceApproval::query()->firstOrCreate(
                ['staff_id' => $staffId, 'month' => $data['month']],
                ['approved_by' => $request->user()->getKey(), 'note' => $data['note'] ?? null],
            );
            $created += $approval->wasRecentlyCreated ? 1 : 0;
        }

        return ApiResponse::success(['signed_off' => $created], status: 201);
    }

    public function destroy(Request $request, AttendanceApproval $attendanceApproval): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::STAFF_ATTENDANCE_APPROVE), 403);

        $attendanceApproval->delete();

        return ApiResponse::noContent();
    }
}
