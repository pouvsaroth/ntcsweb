<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\OvertimeRequest;
use App\Models\PayrollSetting;
use App\Models\Staff;
use App\Models\StaffPayrollProfile;
use App\Services\Payroll\PayrollAttendance;
use App\Support\Query\ApiQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HRM > Payroll's per-staff rule views — each working staff member's tax /
 * NSSF profile (Tax and Social security tabs), and a pay period's overtime
 * pay and attendance deductions (Overtime and Attendance deduction tabs).
 *
 * A period is a month, or one half of it for a 15-day payroll:
 * `month=2026-10` with `period=month|first_half|second_half`.
 */
final class PayrollStaffController extends Controller
{
    public function __construct(
        private readonly PayrollAttendance $attendance,
    ) {}

    public function profiles(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollSetting::class);

        $staff = ApiQuery::for(Staff::query()->whereIn('status', Staff::STATUSES_WORKING), $request)
            ->searchable('first_name', 'last_name', 'employee_code')
            ->sortable(['employee_code', 'first_name'], default: 'employee_code')
            ->maxPerPage(100)
            ->paginate();

        $profiles = StaffPayrollProfile::query()->whereIn('staff_id', $staff->getCollection()->modelKeys())->get()->keyBy('staff_id');

        return ApiResponse::success($staff->through(fn (Staff $member) => [
            'staff' => $this->staffRow($member),
            'profile' => $this->profileRow($profiles->get($member->id) ?? new StaffPayrollProfile(['staff_id' => $member->id])),
        ]));
    }

    public function updateProfile(Request $request, Staff $staff): JsonResponse
    {
        $profile = StaffPayrollProfile::forStaff($staff->id);
        $this->authorize('update', PayrollSetting::current());

        $profile->fill($request->validate([
            'tax_resident' => ['sometimes', 'boolean'],
            'spouse_dependent' => ['sometimes', 'boolean'],
            'child_dependents' => ['sometimes', 'integer', 'min:0', 'max:20'],
            'social_security_enrolled' => ['sometimes', 'boolean'],
            'social_security_number' => ['nullable', 'string', 'max:50'],
        ]))->save();

        return ApiResponse::success(['staff' => $this->staffRow($staff), 'profile' => $this->profileRow($profile)]);
    }

    public function overtime(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollSetting::class);
        [$from, $to] = $this->period($request);

        $staff = Staff::query()
            ->whereIn('status', Staff::STATUSES_WORKING)
            ->whereHas('overtimeRequests', fn ($q) => $q->where('status', OvertimeRequest::STATUS_APPROVED)->whereDate('date', '>=', $from->toDateString())->whereDate('date', '<=', $to->toDateString()))
            ->orderBy('employee_code')
            ->get();
        $rows = $this->attendance->overtime($staff, $from, $to);

        return ApiResponse::success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $staff->map(fn (Staff $member) => ['staff' => $this->staffRow($member), ...$rows[$member->id]])->values(),
        ]);
    }

    public function deductions(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollSetting::class);
        [$from, $to] = $this->period($request);

        $staff = Staff::query()->whereIn('status', Staff::STATUSES_WORKING)->orderBy('employee_code')->get();
        $rows = $this->attendance->deductions($staff, $from, $to);

        // Only who has something to deduct (or something that would be, with a salary).
        $listed = $staff->filter(function (Staff $member) use ($rows) {
            $row = $rows[$member->id];

            return $row['absent_days'] > 0 || $row['unpaid_leave_days'] > 0 || $row['late_times'] > 0 || $row['early_leave_times'] > 0;
        });

        return ApiResponse::success([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'rows' => $listed->map(fn (Staff $member) => ['staff' => $this->staffRow($member), ...$rows[$member->id]])->values(),
        ]);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'month' => ['sometimes', 'date_format:Y-m'],
            'period' => ['sometimes', 'in:month,first_half,second_half'],
        ]);

        $month = CarbonImmutable::createFromFormat('Y-m-d', ($data['month'] ?? now()->format('Y-m')).'-01')->startOfDay();

        return match ($data['period'] ?? 'month') {
            'first_half' => [$month, $month->addDays(14)],
            'second_half' => [$month->addDays(15), $month->endOfMonth()->startOfDay()],
            default => [$month, $month->endOfMonth()->startOfDay()],
        };
    }

    /** @return array<string, mixed> */
    private function staffRow(Staff $staff): array
    {
        return ['id' => $staff->id, 'name' => $staff->fullName(), 'employee_code' => $staff->employee_code];
    }

    /** @return array<string, mixed> */
    private function profileRow(StaffPayrollProfile $profile): array
    {
        return [
            'saved' => $profile->exists,
            'tax_resident' => $profile->tax_resident,
            'spouse_dependent' => $profile->spouse_dependent,
            'child_dependents' => $profile->child_dependents,
            'social_security_enrolled' => $profile->social_security_enrolled,
            'social_security_number' => $profile->social_security_number,
        ];
    }
}
