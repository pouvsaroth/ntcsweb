<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\OvertimeRequest;
use App\Models\PayrollSetting;
use App\Models\Shift;
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
 *
 * Rates (see rateBook()): a plain monthly salary has one daily rate (basic ÷
 * working days per month) and one hourly rate (÷ hours per day). A "pay by
 * shift" salary has one per shift: its amount ÷ the days that shift falls on
 * in the month (from the work schedule), and that ÷ the shift's hours that
 * day — so a missed Saturday comes off at the Saturday shift's rate. A day
 * with no paid shift (a rest day, a shift not on the salary) uses the
 * salary's average: basic ÷ all its shifts' days / hours that month.
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
        $shifts = Shift::query()->with('days')->get()->keyBy('id');
        $multiplier = [
            'normal' => (float) $settings->overtime_normal_rate,
            'rest_day' => (float) $settings->overtime_rest_day_rate,
            'holiday' => (float) $settings->overtime_holiday_rate,
        ];

        $requests = OvertimeRequest::query()
            ->whereIn('staff_id', $staff->modelKeys())
            ->where('status', OvertimeRequest::STATUS_APPROVED)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->get()
            ->groupBy('staff_id');

        return $staff->mapWithKeys(function (Staff $member) use ($salaries, $holidays, $schedules, $default, $shifts, $multiplier, $requests, $to) {
            $schedule = $schedules->get($member->work_schedule_id) ?? $default;
            $minutes = ['normal' => 0, 'rest_day' => 0, 'holiday' => 0];
            $salary = $salaries->get($member->id);
            $book = $salary !== null ? $this->rateBook($salary, $schedule, $shifts, $to) : null;
            $pay = 0.0;

            foreach ($requests->get($member->id, collect()) as $request) {
                $day = CarbonImmutable::parse($request->date->toDateString());
                $kind = match (true) {
                    $this->isHoliday($holidays, $day) => 'holiday',
                    ($schedule !== null ? $schedule->shiftIdOn($day) === null : $day->isSunday()) => 'rest_day',
                    default => 'normal',
                };
                $minutes[$kind] += $request->minutes;
                if ($book !== null) {
                    $pay += $request->minutes * $multiplier[$kind] / 60 * $book['hourly']($day);
                }
            }

            return [$member->id => [
                'minutes' => $minutes,
                'total_minutes' => array_sum($minutes),
                'currency' => $salary?->currency,
                // The average for a "pay by shift" salary; each day's own shift rate is what's paid.
                'hourly_rate' => $book !== null ? round($book['average_hourly'], 4) : null,
                'amount' => $book !== null ? $this->round($pay, $salary->currency) : null,
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
        $schedules = WorkSchedule::query()->get()->keyBy('id');
        $default = $schedules->firstWhere('is_default', true);
        $shifts = Shift::query()->with('days')->get()->keyBy('id');
        $sheet = collect($this->attendance->sheet($staff, $from, $to))->keyBy(fn (array $row) => $row['staff']->id);

        $unpaidLeave = LeaveRequest::query()
            ->whereIn('staff_id', $staff->modelKeys())
            ->where('status', LeaveRequest::STATUS_APPROVED)
            ->whereHas('leaveType', fn ($q) => $q->where('is_paid', false))
            ->whereDate('to_date', '>=', $from->toDateString())
            ->whereDate('from_date', '<=', $to->toDateString())
            ->get()
            ->groupBy('staff_id');

        return $staff->mapWithKeys(function (Staff $member) use ($settings, $salaries, $schedules, $default, $shifts, $sheet, $unpaidLeave, $from, $to) {
            $days = $sheet->get($member->id)['days'] ?? [];
            $grace = $settings->late_grace_minutes;
            $salary = $salaries->get($member->id);
            $book = $salary !== null ? $this->rateBook($salary, $schedules->get($member->work_schedule_id) ?? $default, $shifts, $to) : null;

            // Counts, and what they come to at each day's own rate.
            $absent = 0;
            $absentPay = 0.0;
            $lateTimes = 0;
            $lateMinutes = 0;
            $latePay = 0.0;
            $earlyTimes = 0;
            $earlyMinutes = 0;
            $earlyPay = 0.0;
            foreach ($days as $day) {
                $date = CarbonImmutable::parse($day['date']);
                if ($day['status'] === 'absent') {
                    $absent++;
                    $absentPay += $book !== null ? $book['daily']($date) : 0.0;
                }
                $late = (int) ($day['late_minutes'] ?? 0);
                if ($late > $grace) {
                    $lateTimes++;
                    $lateMinutes += $late - $grace;
                    $latePay += $book !== null ? ($late - $grace) * $book['hourly']($date) / 60 : 0.0;
                }
                $early = (int) ($day['early_leave_minutes'] ?? 0);
                if ($early > $grace) {
                    $earlyTimes++;
                    $earlyMinutes += $early - $grace;
                    $earlyPay += $book !== null ? ($early - $grace) * $book['hourly']($date) / 60 : 0.0;
                }
            }

            $unpaidDays = 0.0;
            $unpaidPay = 0.0;
            foreach ($unpaidLeave->get($member->id, collect()) as $request) {
                $start = CarbonImmutable::parse(max($request->from_date->toDateString(), $from->toDateString()));
                $end = CarbonImmutable::parse(min($request->to_date->toDateString(), $to->toDateString()));
                $part = $request->day_part !== null && $request->day_part !== LeaveRequest::DAY_FULL ? 0.5 : 1.0;
                foreach ($this->leave->workingDates($member, $start, $end) as $date) {
                    $unpaidDays += $part;
                    $unpaidPay += $book !== null ? $part * $book['daily'](CarbonImmutable::parse($date)) : 0.0;
                }
            }

            $lines = null;
            if ($salary !== null) {
                $fixed = $salary->currency === Tenant::CURRENCY_KHR ? $settings->late_amount_khr : $settings->late_amount_usd;
                $byTime = fn (int $times, float $perMinutePay) => match ($settings->late_deduction_mode) {
                    PayrollSetting::LATE_PER_MINUTE => $perMinutePay,
                    PayrollSetting::LATE_PER_OCCURRENCE => $times * $fixed,
                    default => 0.0,
                };

                $lines = [
                    'absence' => $settings->deduct_absence ? $this->round($absentPay, $salary->currency) : 0.0,
                    'unpaid_leave' => $settings->deduct_unpaid_leave ? $this->round($unpaidPay, $salary->currency) : 0.0,
                    'late' => $this->round($byTime($lateTimes, $latePay), $salary->currency),
                    'early_leave' => $settings->deduct_early_leave ? $this->round($byTime($earlyTimes, $earlyPay), $salary->currency) : 0.0,
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
            ->with('shifts.shift')
            ->get()
            ->keyBy('staff_id');
    }

    /**
     * A salary's rates for the month `$on` falls in — see the class comment.
     * `daily` / `hourly` take a date; `shifts` lists each paid shift's rates
     * (empty for a plain monthly salary), for the payslip.
     *
     * @param  Collection<int, Shift>  $shifts  every shift, keyed by id, days loaded
     * @return array{daily: \Closure(CarbonImmutable): float, hourly: \Closure(CarbonImmutable): float, average_hourly: float, shifts: list<array<string, mixed>>}
     */
    public function rateBook(StaffSalary $salary, ?WorkSchedule $schedule, Collection $shifts, CarbonImmutable $on): array
    {
        $basic = (float) $salary->basic_salary;
        $lines = $salary->relationLoaded('shifts') ? $salary->shifts : $salary->shifts()->get();

        if ($lines->isEmpty()) {
            $daily = $this->rules->dailyRate($basic);
            $hourly = $this->rules->hourlyRate($basic);

            return ['daily' => fn () => $daily, 'hourly' => fn () => $hourly, 'average_hourly' => $hourly, 'shifts' => []];
        }

        // How many days, and minutes, each paid shift falls on this month.
        $paid = $lines->keyBy('shift_id');
        $days = [];
        $minutes = [];
        $monthStart = $on->startOfMonth();
        for ($day = $monthStart; $day->month === $monthStart->month; $day = $day->addDay()) {
            $id = $schedule?->shiftIdOn($day);
            if ($id === null || ! $paid->has($id) || ! $shifts->has($id)) {
                continue;
            }
            $days[$id] = ($days[$id] ?? 0) + 1;
            $minutes[$id] = ($minutes[$id] ?? 0) + $shifts[$id]->workMinutesOn($day);
        }

        $totalDays = array_sum($days);
        $totalHours = array_sum($minutes) / 60;
        // Only shifts actually on the schedule count toward the average; with none, the school-wide rates.
        $scheduledPay = $paid->filter(fn ($line) => isset($days[$line->shift_id]))->sum(fn ($line) => (float) $line->amount);
        $averageDaily = $totalDays > 0 ? $scheduledPay / $totalDays : $this->rules->dailyRate($basic);
        $averageHourly = $totalHours > 0 ? $scheduledPay / $totalHours : $this->rules->hourlyRate($basic);

        $dailyOf = fn (int $id) => (float) $paid[$id]->amount / $days[$id];
        $paidShiftOn = fn (CarbonImmutable $day) => ($id = $schedule?->shiftIdOn($day)) !== null && isset($days[$id]) ? $id : null;

        return [
            'daily' => fn (CarbonImmutable $day) => ($id = $paidShiftOn($day)) !== null ? $dailyOf($id) : $averageDaily,
            'hourly' => function (CarbonImmutable $day) use ($paidShiftOn, $dailyOf, $shifts, $averageHourly) {
                $id = $paidShiftOn($day);
                $workMinutes = $id !== null ? $shifts[$id]->workMinutesOn($day) : 0;

                return $workMinutes > 0 ? $dailyOf($id) / ($workMinutes / 60) : $averageHourly;
            },
            'average_hourly' => $averageHourly,
            'shifts' => $lines->map(fn ($line) => [
                'shift_id' => $line->shift_id,
                'name' => $line->shift?->name,
                'amount' => (float) $line->amount,
                'days' => $days[$line->shift_id] ?? 0,
                'hours' => round(($minutes[$line->shift_id] ?? 0) / 60, 2),
                'daily_rate' => isset($days[$line->shift_id]) ? round($dailyOf($line->shift_id), 4) : null,
                'hourly_rate' => ($minutes[$line->shift_id] ?? 0) > 0 ? round((float) $line->amount / ($minutes[$line->shift_id] / 60), 4) : null,
            ])->values()->all(),
        ];
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
