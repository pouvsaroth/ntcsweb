<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollSetting;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveBalanceService;
use App\Services\StaffAttendance\StaffAttendanceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * HRM > Payroll's Overtime and Attendance deduction, worked out for a pay
 * period from what Attendance & Time already records:
 *
 * - Overtime: approved overtime requests, paid at the hourly rate times the
 *   multiplier for the kind of day — a holiday, a rest day (no shift on the
 *   staff member's work schedule), or a normal day.
 * - Attendance deduction: absent days and unpaid leave days at the daily
 *   rate, and late / early-leave minutes past the grace period, per minute
 *   at the hourly rate or a fixed amount each time — as PayrollSetting says.
 *
 * Both use the basic salary in effect at the end of the period, in its own
 * currency. A staff member with no salary is listed with no amount.
 */
final class PayrollAttendance
{
    public function __construct(
        private readonly PayrollRules $rules,
        private readonly StaffAttendanceService $attendance,
        private readonly LeaveBalanceService $leave,
    ) {}

    /**
     * @param  Collection<int, Staff>  $staff
     * @return Collection<int, array<string, mixed>> keyed by staff id
     */
    public function overtime(Collection $staff, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $settings = $this->rules->settings();
        $salaries = $this->salaries($staff, $to);
        $holidays = $this->holidays($from, $to);
        $schedules = WorkSchedule::query()->get()->keyBy('id');
        $default = $schedules->firstWhere('is_default', true);

        $requests = OvertimeRequest::query()
            ->whereIn('staff_id', $staff->modelKeys())
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->groupBy('staff_id');

        return $staff->mapWithKeys(function (Staff $member) use ($settings, $salaries, $holidays, $schedules, $default, $requests) {
            $schedule = $schedules->get($member->work_schedule_id) ?? $default;
            $minutes = ['normal' => 0, 'rest_day' => 0, 'holiday' => 0];

            foreach ($requests->get($member->id, collect()) as $request) {
                $day = CarbonImmutable::parse($request->date->toDateString());
                $kind = match (true) {
                    $this->isHoliday($holidays, $day) => 'holiday',
                    ($schedule !== null ? $schedule->shiftIdOn($day) === null : $day->isSunday()) => 'rest_day',
                    default => 'normal',
                };
                $minutes[$kind] += $request->minutes;
            }

            $salary = $salaries->get($member->id);
            $hourly = $salary !== null ? $this->rules->hourlyRate((float) $salary->basic_salary) : null;
            $amount = $hourly === null ? null : $this->round(
                ($minutes['normal'] * $settings->overtime_normal_rate
                    + $minutes['rest_day'] * $settings->overtime_rest_day_rate
                    + $minutes['holiday'] * $settings->overtime_holiday_rate) / 60 * $hourly,
                $salary->currency,
            );

            return [$member->id => [
                'minutes' => $minutes,
                'total_minutes' => array_sum($minutes),
                'currency' => $salary?->currency,
                'hourly_rate' => $hourly !== null ? round($hourly, 4) : null,
                'amount' => $amount,
            ]];
        });
    }

    /**
     * @param  Collection<int, Staff>  $staff
     * @return Collection<int, array<string, mixed>> keyed by staff id
     */
    public function deductions(Collection $staff, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $settings = $this->rules->settings();
        $salaries = $this->salaries($staff, $to);
        $sheet = collect($this->attendance->sheet($staff, $from, $to))->keyBy(fn (array $row) => $row['staff']->id);

        $unpaidLeave = LeaveRequest::query()
            ->whereIn('staff_id', $staff->modelKeys())
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false))
            ->whereDate('to_date', '>=', $from->toDateString())
            ->whereDate('from_date', '<=', $to->toDateString())
            ->get()
            ->groupBy('staff_id');

        return $staff->mapWithKeys(function (Staff $member) use ($settings, $salaries, $sheet, $unpaidLeave, $from, $to) {
            $days = $sheet->get($member->id)['days'] ?? [];
            $grace = $settings->late_grace_minutes;

            $absent = count(array_filter($days, fn (array $d) => $d['status'] === 'absent'));
            $lateTimes = 0;
            $lateMinutes = 0;
            $earlyTimes = 0;
            $earlyMinutes = 0;
            foreach ($days as $day) {
                $late = (int) ($day['late_minutes'] ?? 0);
                if ($late > $grace) {
                    $lateTimes++;
                    $lateMinutes += $late - $grace;
                }
                $early = (int) ($day['early_leave_minutes'] ?? 0);
                if ($early > $grace) {
                    $earlyTimes++;
                    $earlyMinutes += $early - $grace;
                }
            }

            $unpaidDays = 0.0;
            foreach ($unpaidLeave->get($member->id, collect()) as $request) {
                $start = CarbonImmutable::parse(max($request->from_date->toDateString(), $from->toDateString()));
                $end = CarbonImmutable::parse(min($request->to_date->toDateString(), $to->toDateString()));
                $count = count($this->leave->workingDates($member, $start, $end));
                $unpaidDays += $request->day_part !== null && $request->day_part !== LeaveRequest::DAY_FULL ? $count * 0.5 : $count;
            }

            $salary = $salaries->get($member->id);
            $lines = null;
            if ($salary !== null) {
                $basic = (float) $salary->basic_salary;
                $daily = $this->rules->dailyRate($basic);
                $perMinute = $this->rules->hourlyRate($basic) / 60;
                $fixed = $salary->currency === Tenant::CURRENCY_KHR ? $settings->late_amount_khr : $settings->late_amount_usd;
                $byTime = fn (int $times, int $minutes) => match ($settings->late_deduction_mode) {
                    PayrollSetting::LATE_PER_MINUTE => $minutes * $perMinute,
                    PayrollSetting::LATE_PER_OCCURRENCE => $times * $fixed,
                    default => 0.0,
                };

                $lines = [
                    'absence' => $settings->deduct_absence ? $this->round($absent * $daily, $salary->currency) : 0.0,
                    'unpaid_leave' => $settings->deduct_unpaid_leave ? $this->round($unpaidDays * $daily, $salary->currency) : 0.0,
                    'late' => $this->round($byTime($lateTimes, $lateMinutes), $salary->currency),
                    'early_leave' => $settings->deduct_early_leave ? $this->round($byTime($earlyTimes, $earlyMinutes), $salary->currency) : 0.0,
                ];
            }

            return [$member->id => [
                'absent_days' => $absent,
                'unpaid_leave_days' => $unpaidDays,
                'late_times' => $lateTimes,
                'late_minutes' => $lateMinutes,
                'early_leave_times' => $earlyTimes,
                'early_leave_minutes' => $earlyMinutes,
                'currency' => $salary?->currency,
                'lines' => $lines,
                'amount' => $lines !== null ? $this->round(array_sum($lines), $salary->currency) : null,
            ]];
        });
    }

    /**
     * @param  Collection<int, Staff>  $staff
     * @return Collection<int, StaffSalary> keyed by staff id
     */
    public function salaries(Collection $staff, CarbonImmutable $on): Collection
    {
        return StaffSalary::query()
            ->whereIn('staff_id', $staff->modelKeys())
            ->effectiveOn($on->toDateString())
            ->get()
            ->keyBy('staff_id');
    }

    /** Riel to the whole riel, dollars to the cent. */
    public function round(float $amount, string $currency): float
    {
        return $currency === Tenant::CURRENCY_KHR ? round($amount) : round($amount, 2);
    }

    /** @return Collection<int, Holiday> */
    private function holidays(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Holiday::query()
            ->whereDate('end_date', '>=', $from->toDateString())
            ->whereDate('start_date', '<=', $to->toDateString())
            ->get();
    }

    /** @param  Collection<int, Holiday>  $holidays */
    private function isHoliday(Collection $holidays, CarbonImmutable $day): bool
    {
        $date = $day->toDateString();

        return $holidays->contains(fn (Holiday $h) => $h->start_date->toDateString() <= $date && $h->end_date->toDateString() >= $date);
    }
}
