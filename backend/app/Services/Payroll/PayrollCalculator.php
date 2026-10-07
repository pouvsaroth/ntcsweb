<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\SalaryStructure;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\StaffPayComponent;
use App\Models\StaffPayrollProfile;
use App\Models\StaffSalary;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Works out every payslip of a payroll run — each working staff member with
 * a basic salary on the period's last day, in their salary currency:
 *
 *   basic (half of it for a 15-day period)
 * + allowances and bonuses (salary structure, overridden / added to by the
 *   staff member's own; recurring ones halved for 15 days, one-time ones in
 *   full in the period covering their date; a % is of the monthly basic)
 * + overtime (PayrollAttendance)
 * = gross
 * − attendance deduction (PayrollAttendance)
 * − deductions (as allowances)
 * − NSSF staff share and Tax on Salary (PayrollRules, in riel)
 * + adjustment (typed in on the payslip, kept when recalculated)
 * − loan installments (never more than what's left to pay)
 * = net
 *
 * Tax and NSSF are monthly: a 1st–15th run takes none, and the 16th–end
 * run works them out on the whole month — its own pay plus the first
 * half's (when that run exists and isn't cancelled/rejected).
 */
final class PayrollCalculator
{
    public function __construct(
        private readonly PayrollRules $rules,
        private readonly PayrollAttendance $attendance,
    ) {}

    /** Rebuilds the run's payslips (keeping each one's adjustment). */
    public function build(PayrollRun $run): void
    {
        $from = CarbonImmutable::parse($run->period_start->toDateString());
        $to = CarbonImmutable::parse($run->period_end->toDateString());
        $factor = $run->period === PayrollRun::PERIOD_MONTH ? 1.0 : 0.5;
        $rate = $this->rules->khrPerUsd($to->toDateString());

        $staff = Staff::query()
            ->whereIn('status', Staff::STATUSES_WORKING)
            ->where(fn ($q) => $q->whereNull('hire_date')->orWhereDate('hire_date', '<=', $to->toDateString()))
            ->orderBy('employee_code')
            ->get();
        $salaries = $this->attendance->salaries($staff, $to);
        $staff = $staff->filter(fn (Staff $member) => $salaries->has($member->id))->values();

        $taxed = $run->period !== PayrollRun::PERIOD_FIRST_HALF;
        if ($taxed && $rate === null && $salaries->contains(fn (StaffSalary $s) => $s->currency === Tenant::CURRENCY_USD)) {
            throw ValidationException::withMessages(['khr_per_usd' => 'Tax and NSSF are worked out in riel — add a currency rate (Settings > Currency rates) before working out USD salaries.']);
        }

        $overtime = $this->attendance->overtime($staff, $from, $to);
        $deductions = $this->attendance->deductions($staff, $from, $to);
        $profiles = StaffPayrollProfile::query()->whereIn('staff_id', $staff->modelKeys())->get()->keyBy('staff_id');
        $structures = SalaryStructure::query()->with('items.component')->whereIn('id', $salaries->pluck('salary_structure_id')->filter())->get()->keyBy('id');
        $assignments = StaffPayComponent::query()
            ->with('component')
            ->whereIn('staff_id', $staff->modelKeys())
            ->whereDate('starts_on', '<=', $to->toDateString())
            ->where(fn ($q) => $q
                ->where(fn ($r) => $r->where('recurrence', StaffPayComponent::RECURRING)->where(fn ($e) => $e->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from->toDateString())))
                ->orWhere(fn ($o) => $o->where('recurrence', StaffPayComponent::ONCE)->whereDate('starts_on', '>=', $from->toDateString())))
            ->get()
            ->groupBy('staff_id');
        $loans = StaffLoan::query()
            ->withSum('repayments', 'amount')
            ->whereIn('staff_id', $staff->modelKeys())
            ->where('status', StaffLoan::STATUS_ACTIVE)
            ->whereDate('first_deduction_on', '<=', $to->toDateString())
            ->orderBy('first_deduction_on')
            ->get()
            ->groupBy('staff_id');
        $firstHalf = $run->period === PayrollRun::PERIOD_SECOND_HALF ? $this->firstHalfSlips($run) : collect();
        $kept = $run->payslips()->get(['staff_id', 'adjustment', 'adjustment_note'])->keyBy('staff_id');

        $run->payslips()->delete();

        foreach ($staff as $member) {
            $slip = $this->slip(
                $member,
                $salaries[$member->id],
                $factor,
                $from,
                $to,
                $taxed,
                $rate,
                $structures,
                $assignments->get($member->id, collect()),
                $overtime[$member->id],
                $deductions[$member->id],
                $profiles->get($member->id) ?? new StaffPayrollProfile(['staff_id' => $member->id]),
                $loans->get($member->id, collect()),
                $firstHalf->get($member->id),
                (float) ($kept->get($member->id)?->adjustment ?? 0),
                $kept->get($member->id)?->adjustment_note,
            );
            $run->payslips()->create($slip);
        }

        $run->update(['khr_per_usd' => $rate, 'calculated_at' => now()]);
    }

    /** Applies a payslip's adjustment again without redoing the rest — the net, and how much of the loans it can still take. */
    public function adjust(Payslip $slip, float $adjustment, ?string $note): void
    {
        $details = $slip->details;
        $beforeLoan = $this->round($slip->gross_pay - $slip->attendance_deduction - $slip->other_deductions - $slip->social_security_employee - $slip->tax + $adjustment, $slip->currency);
        [$loanLines, $loanTotal] = $this->loanTake(collect($details['loans_open'] ?? []), $beforeLoan, $slip->currency);
        $details['loans'] = $loanLines;

        $slip->update([
            'adjustment' => $adjustment,
            'adjustment_note' => $note,
            'loan_deduction' => $loanTotal,
            'net_pay' => $this->round($beforeLoan - $loanTotal, $slip->currency),
            'lines' => $this->withAdjustmentAndLoans($slip->lines, $adjustment, $note, $loanLines),
            'details' => $details,
        ]);
    }

    /** @return array<string, mixed> the payslip's attributes */
    private function slip(
        Staff $member,
        StaffSalary $salary,
        float $factor,
        CarbonImmutable $from,
        CarbonImmutable $to,
        bool $taxed,
        ?float $rate,
        Collection $structures,
        Collection $assignments,
        array $overtime,
        array $deduction,
        StaffPayrollProfile $profile,
        Collection $loans,
        ?Payslip $firstHalf,
        float $adjustment,
        ?string $adjustmentNote,
    ): array {
        $currency = $salary->currency;
        $monthly = (float) $salary->basic_salary;
        $basic = $this->round($monthly * $factor, $currency);

        // The structure's items, then the staff member's own on top: a
        // recurring one replaces the structure's for the same component;
        // a one-time one is always extra.
        $items = [];
        foreach ($structures->get($salary->salary_structure_id)?->items ?? [] as $item) {
            if ($item->component !== null && $item->component->is_active) {
                $items['c'.$item->payroll_component_id] = ['component' => $item->component, 'amount' => (float) $item->amount, 'recurring' => true];
            }
        }
        foreach ($assignments as $assignment) {
            if ($assignment->component === null) {
                continue;
            }
            $recurring = $assignment->recurrence === StaffPayComponent::RECURRING;
            $key = $recurring ? 'c'.$assignment->payroll_component_id : 'once'.$assignment->id;
            $items[$key] = ['component' => $assignment->component, 'amount' => (float) $assignment->amount, 'recurring' => $recurring];
        }

        $earnings = [];
        $componentDeductions = [];
        $totals = ['allowance' => 0.0, 'bonus' => 0.0, 'deduction' => 0.0];
        // What counts toward tax / NSSF: + earnings, − deductions.
        $taxable = 0.0;
        $nssfable = 0.0;
        foreach ($items as $entry) {
            /** @var PayrollComponent $component */
            $component = $entry['component'];
            $value = $component->calculation === PayrollComponent::CALCULATION_PERCENT_OF_BASIC ? $monthly * $entry['amount'] / 100 : $entry['amount'];
            $value = $this->round($entry['recurring'] ? $value * $factor : $value, $currency);
            if ($value == 0.0) {
                continue;
            }

            $line = ['code' => $component->code, 'name' => $component->name, 'amount' => $value];
            $sign = $component->kind === PayrollComponent::KIND_DEDUCTION ? -1 : 1;
            $totals[$component->kind] += $value;
            $taxable += $component->affects_tax ? $sign * $value : 0;
            $nssfable += $component->affects_social_security ? $sign * $value : 0;
            if ($sign < 0) {
                $componentDeductions[] = [...$line, 'type' => 'deduction'];
            } else {
                $earnings[] = [...$line, 'type' => $component->kind];
            }
        }

        $overtimePay = (float) ($overtime['amount'] ?? 0);
        $attendance = (float) ($deduction['amount'] ?? 0);
        $gross = $this->round($basic + $totals['allowance'] + $totals['bonus'] + $overtimePay, $currency);

        // Basic and overtime always count toward tax and NSSF; attendance deductions come off both.
        $taxablePay = $basic + $overtimePay + $taxable - $attendance;
        $nssfPay = $basic + $overtimePay + $nssfable - $attendance;

        $nssf = ['lines' => [], 'employee' => 0.0, 'employer' => 0.0, 'reduces_taxable' => 0.0];
        $tax = ['taxable' => 0.0, 'allowances' => 0.0, 'base' => 0.0, 'tax' => 0.0];
        $nssfEmployee = 0.0;
        $nssfEmployer = 0.0;
        $taxAmount = 0.0;
        if ($taxed) {
            // The month's pay: this period's plus the first half's, if there was one.
            $monthTaxable = $taxablePay + (float) ($firstHalf?->details['taxable_pay'] ?? 0);
            $monthNssf = $nssfPay + (float) ($firstHalf?->details['nssf_pay'] ?? 0);
            $nssf = $this->rules->socialSecurity($this->rules->toKhr(max($monthNssf, 0), $currency, $rate), $profile->social_security_enrolled);
            $tax = $this->rules->tax($this->rules->toKhr(max($monthTaxable, 0), $currency, $rate) - $nssf['reduces_taxable'], $profile);
            $nssfEmployee = $this->fromKhr($nssf['employee'], $currency, $rate);
            $nssfEmployer = $this->fromKhr($nssf['employer'], $currency, $rate);
            $taxAmount = $this->fromKhr($tax['tax'], $currency, $rate);
        }

        $beforeLoan = $this->round($gross - $attendance - $totals['deduction'] - $nssfEmployee - $taxAmount + $adjustment, $currency);
        $openLoans = $loans
            ->filter(fn (StaffLoan $loan) => $loan->currency === $currency)
            ->map(fn (StaffLoan $loan) => ['loan_id' => $loan->id, 'type' => $loan->type, 'installment' => (float) $loan->installment_amount, 'balance' => $loan->balance()])
            ->values();
        [$loanLines, $loanTotal] = $this->loanTake($openLoans, $beforeLoan, $currency);

        $deductionLines = [];
        foreach (['absence', 'unpaid_leave', 'late', 'early_leave'] as $key) {
            $amount = (float) ($deduction['lines'][$key] ?? 0);
            if ($amount > 0) {
                $deductionLines[] = ['type' => 'attendance', 'code' => $key, 'name' => $key, 'amount' => $amount];
            }
        }
        $deductionLines = [...$deductionLines, ...$componentDeductions];
        if ($nssfEmployee > 0) {
            $deductionLines[] = ['type' => 'social_security', 'code' => 'nssf', 'name' => 'nssf', 'amount' => $nssfEmployee];
        }
        if ($taxAmount > 0) {
            $deductionLines[] = ['type' => 'tax', 'code' => 'tax', 'name' => 'tax', 'amount' => $taxAmount];
        }

        $earningLines = [['type' => 'basic', 'code' => 'basic', 'name' => 'basic', 'amount' => $basic], ...$earnings];
        if ($overtimePay > 0) {
            $earningLines[] = ['type' => 'overtime', 'code' => 'overtime', 'name' => 'overtime', 'amount' => $overtimePay];
        }

        $lines = [
            'earnings' => $earningLines,
            'deductions' => $deductionLines,
            'employer' => $nssfEmployer > 0 ? [['type' => 'social_security', 'code' => 'nssf', 'name' => 'nssf', 'amount' => $nssfEmployer]] : [],
        ];

        return [
            'staff_id' => $member->id,
            'currency' => $currency,
            'monthly_basic' => $monthly,
            'basic_pay' => $basic,
            'allowances' => $this->round($totals['allowance'], $currency),
            'bonuses' => $this->round($totals['bonus'], $currency),
            'overtime_pay' => $overtimePay,
            'gross_pay' => $gross,
            'attendance_deduction' => $attendance,
            'other_deductions' => $this->round($totals['deduction'], $currency),
            'social_security_employee' => $nssfEmployee,
            'social_security_employer' => $nssfEmployer,
            'tax' => $taxAmount,
            'loan_deduction' => $loanTotal,
            'adjustment' => $adjustment,
            'adjustment_note' => $adjustmentNote,
            'net_pay' => $this->round($beforeLoan - $loanTotal, $currency),
            'lines' => $this->withAdjustmentAndLoans($lines, $adjustment, $adjustmentNote, $loanLines),
            'details' => [
                'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
                'overtime' => ['minutes' => $overtime['minutes'] ?? null, 'hourly_rate' => $overtime['hourly_rate'] ?? null],
                'attendance' => collect($deduction)->only(['absent_days', 'unpaid_leave_days', 'late_times', 'late_minutes', 'early_leave_times', 'early_leave_minutes'])->all(),
                // What this period adds to the month's tax / NSSF — the 16th–end run reads the first half's.
                'taxable_pay' => round($taxablePay, 2),
                'nssf_pay' => round($nssfPay, 2),
                'taxed' => $taxed,
                'khr_per_usd' => $rate,
                'tax' => $tax,
                'social_security' => $nssf['lines'],
                'social_security_enrolled' => $profile->social_security_enrolled,
                'tax_resident' => $profile->tax_resident,
                'loans_open' => $openLoans->all(),
                'loans' => $loanLines,
                'structure' => $salary->salary_structure_id !== null ? $structures->get($salary->salary_structure_id)?->name : null,
            ],
            'payment_method' => $salary->payment_method,
            'bank_name' => $salary->bank_name,
            'bank_account_name' => $salary->bank_account_name,
            'bank_account_number' => $salary->bank_account_number,
        ];
    }

    /**
     * Each loan's installment, but never more than it still owes nor more
     * than the pay left to take it from.
     *
     * @param  Collection<int, array{loan_id: int, type: string, installment: float, balance: float}>  $loans
     * @return array{0: list<array{loan_id: int, type: string, amount: float}>, 1: float}
     */
    private function loanTake(Collection $loans, float $available, string $currency): array
    {
        $lines = [];
        $total = 0.0;
        foreach ($loans as $loan) {
            $take = $this->round(min($loan['installment'], $loan['balance'], max($available - $total, 0)), $currency);
            if ($take > 0) {
                $lines[] = ['loan_id' => $loan['loan_id'], 'type' => $loan['type'], 'amount' => $take];
                $total += $take;
            }
        }

        return [$lines, $this->round($total, $currency)];
    }

    /** @param  array<string, list<array<string, mixed>>>  $lines */
    private function withAdjustmentAndLoans(array $lines, float $adjustment, ?string $note, array $loanLines): array
    {
        $deductions = array_values(array_filter($lines['deductions'], fn (array $l) => ! in_array($l['type'], ['loan', 'adjustment'], true)));
        $earnings = array_values(array_filter($lines['earnings'], fn (array $l) => $l['type'] !== 'adjustment'));

        if ($adjustment > 0) {
            $earnings[] = ['type' => 'adjustment', 'code' => 'adjustment', 'name' => $note ?? 'adjustment', 'amount' => $adjustment];
        } elseif ($adjustment < 0) {
            $deductions[] = ['type' => 'adjustment', 'code' => 'adjustment', 'name' => $note ?? 'adjustment', 'amount' => -$adjustment];
        }
        foreach ($loanLines as $loan) {
            $deductions[] = ['type' => 'loan', 'code' => $loan['type'], 'name' => $loan['type'], 'amount' => $loan['amount'], 'loan_id' => $loan['loan_id']];
        }

        return [...$lines, 'earnings' => $earnings, 'deductions' => $deductions];
    }

    /** @return Collection<int, Payslip> keyed by staff id */
    private function firstHalfSlips(PayrollRun $run): Collection
    {
        $first = PayrollRun::query()
            ->where('month', $run->month)
            ->where('period', PayrollRun::PERIOD_FIRST_HALF)
            ->whereNotIn('status', [PayrollRun::STATUS_CANCELLED, PayrollRun::STATUS_REJECTED])
            ->first();

        return $first?->payslips()->get()->keyBy('staff_id') ?? collect();
    }

    private function fromKhr(float $khr, string $currency, ?float $rate): float
    {
        return $this->round($this->rules->fromKhr($khr, $currency, $rate), $currency);
    }

    private function round(float $amount, string $currency): float
    {
        return $this->attendance->round($amount, $currency);
    }
}
