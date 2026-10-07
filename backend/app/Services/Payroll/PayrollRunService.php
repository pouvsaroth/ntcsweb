<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\StaffLoan;
use App\Models\StaffLoanRepayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Accounting\ExpenseService;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Notifications\NotificationService;
use App\Support\Accounting\AccountType;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A payroll run's life — see PayrollRun:
 *
 * - create(): a pay period nothing else holds, worked out straight away.
 * - recalculate() / adjust(): while it's a draft (or rejected).
 * - submit(): to approval — the payroll Approval Flow's first group, or
 *   whoever holds payroll.approve; payslips are fixed from here.
 * - approve() / reject(): see ApprovalFlow (step by step when there's a flow).
 * - pay(): posts the net pay to Accounting as one paid expense (from the
 *   chosen cash/bank account) and takes the loan installments.
 * - cancel(): any time before it's paid.
 */
final class PayrollRunService
{
    public function __construct(
        private readonly PayrollCalculator $calculator,
        private readonly PayrollRules $rules,
        private readonly ApprovalFlow $flow,
        private readonly NotificationService $notifications,
        private readonly ExpenseService $expenses,
        private readonly TenantContext $context,
    ) {}

    public function create(string $month, string $period, string $payDate, ?string $note, User $actor): PayrollRun
    {
        $start = CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
        [$from, $to] = match ($period) {
            PayrollRun::PERIOD_FIRST_HALF => [$start, $start->addDays(14)],
            PayrollRun::PERIOD_SECOND_HALF => [$start->addDays(15), $start->endOfMonth()->startOfDay()],
            default => [$start, $start->endOfMonth()->startOfDay()],
        };

        // A whole-month run and a half-month run of the same month would pay twice.
        $clash = PayrollRun::query()
            ->where('month', $month)
            ->whereIn('status', PayrollRun::STATUSES_HOLDING)
            ->where(fn ($q) => $period === PayrollRun::PERIOD_MONTH ? $q : $q->whereIn('period', [PayrollRun::PERIOD_MONTH, $period]))
            ->first();
        if ($clash !== null) {
            throw ValidationException::withMessages(['period' => "Payroll {$clash->reference} already covers this pay period. Cancel it first to start again."]);
        }

        return DB::connection('tenant')->transaction(function () use ($month, $period, $from, $to, $payDate, $note, $actor) {
            $run = PayrollRun::query()->create([
                'reference' => $this->reference($month, $period),
                'month' => $month,
                'period' => $period,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
                'pay_date' => $payDate,
                'note' => $note,
                'created_by' => $actor->getKey(),
            ]);
            $this->calculator->build($run);

            return $run->fresh();
        });
    }

    public function recalculate(PayrollRun $run): PayrollRun
    {
        $this->assertEditable($run);

        DB::connection('tenant')->transaction(fn () => $this->calculator->build($run));

        return $run->fresh();
    }

    public function adjust(Payslip $slip, float $adjustment, ?string $note): Payslip
    {
        $this->assertEditable($slip->run);

        $this->calculator->adjust($slip, $adjustment, $note);

        return $slip->fresh();
    }

    public function submit(PayrollRun $run, User $actor): PayrollRun
    {
        $this->assertEditable($run);

        if (! $run->payslips()->exists()) {
            throw ValidationException::withMessages(['status' => 'This payroll has no payslips — set staff basic salaries first, then recalculate.']);
        }

        $run->update([
            'status' => PayrollRun::STATUS_PENDING,
            'requested_by' => $actor->getKey(),
            'submitted_at' => now(),
            'decided_by' => null,
            'decided_at' => null,
            'decision_reason' => null,
        ]);

        $this->notifyApprovers($run, $this->flow->submitRecipients(
            $run,
            fn () => $this->notifications->usersWithPermission(Permissions::PAYROLL_APPROVE),
        ));

        return $run->fresh();
    }

    /** With a flow, approves the current step (and tells the next group); the last step — or no flow — approves the run. */
    public function approve(PayrollRun $run, User $actor): PayrollRun
    {
        $this->flow->authorizeDecision($run, $actor, 'approve');

        return $this->flow->approve(
            $run,
            $actor,
            fn (PayrollRun $doc) => $this->finalApprove($doc, $actor),
            fn (PayrollRun $doc, Collection $next) => $this->notifyApprovers($doc, $next),
        );
    }

    public function reject(PayrollRun $run, string $reason, User $actor): PayrollRun
    {
        $this->flow->authorizeDecision($run, $actor, 'reject');

        $run = DB::connection('tenant')->transaction(function () use ($run, $reason, $actor) {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PayrollRun::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This payroll is not waiting for approval.']);
            }

            $locked->update([
                'status' => PayrollRun::STATUS_REJECTED,
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_reason' => $reason,
            ]);

            return $locked->fresh();
        });

        $this->notifyRequester($run, NotificationType::PAYROLL_RUN_REJECTED, ['reason' => $reason]);

        return $run;
    }

    /**
     * Pays an approved run: the net pay of every payslip, in the school's
     * accounting currency (USD and riel payslips converted at the run's
     * rate), becomes one paid expense on `$expenseAccount` from
     * `$cashAccount`; each loan installment becomes a payroll repayment.
     */
    public function pay(PayrollRun $run, Account $expenseAccount, Account $cashAccount, string $paidOn, User $actor): PayrollRun
    {
        if ($expenseAccount->type !== AccountType::EXPENSE) {
            throw ValidationException::withMessages(['expense_account_id' => 'Pick an expense account (e.g. Salary).']);
        }
        if (! $cashAccount->is_bank_or_cash) {
            throw ValidationException::withMessages(['cash_account_id' => 'Pick a cash or bank account to pay from.']);
        }

        return DB::connection('tenant')->transaction(function () use ($run, $expenseAccount, $cashAccount, $paidOn, $actor) {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PayrollRun::STATUS_APPROVED) {
                throw ValidationException::withMessages(['status' => 'Only an approved payroll can be paid.']);
            }

            $slips = $locked->payslips()->with('staff')->get();
            $currency = $this->context->getOrFail()->default_currency ?? Tenant::CURRENCY_USD;
            $rate = $locked->khr_per_usd ?? $this->rules->khrPerUsd($paidOn);
            if ($rate === null && $slips->contains(fn (Payslip $s) => $s->currency !== $currency && $s->net_pay != 0.0)) {
                throw ValidationException::withMessages(['cash_account_id' => 'Some payslips are in another currency — add a currency rate (Settings > Currency rates) first.']);
            }

            $total = round($slips->sum(fn (Payslip $s) => $this->convert($s->net_pay, $s->currency, $currency, $rate)), 2);

            $expenseId = null;
            if ($total > 0) {
                $expense = $this->expenses->create([
                    'expense_date' => $paidOn,
                    'account_id' => $expenseAccount->id,
                    'amount' => $total,
                    'payment_method' => 'payroll',
                    'vendor' => 'Payroll',
                    'description' => "Payroll {$locked->reference} — {$slips->count()} staff",
                    'reference_number' => $locked->reference,
                    'status' => ExpenseStatus::APPROVED,
                ], $actor);
                // Approved through the payroll's own approval.
                $expense->update(['approved_by' => $locked->decided_by, 'approved_at' => $locked->decided_at, 'reference_type' => PayrollRun::class, 'reference_id' => $locked->id]);
                $this->expenses->pay($expense, $cashAccount, $actor, $paidOn);
                $expenseId = $expense->id;
            }

            foreach ($slips as $slip) {
                foreach ($slip->details['loans'] ?? [] as $take) {
                    $loan = StaffLoan::query()->whereKey($take['loan_id'])->lockForUpdate()->first();
                    if ($loan === null || $loan->status !== StaffLoan::STATUS_ACTIVE) {
                        continue;
                    }
                    $amount = min((float) $take['amount'], $loan->balance());
                    if ($amount > 0) {
                        $loan->repayments()->create([
                            'amount' => $amount,
                            'paid_on' => $paidOn,
                            'method' => StaffLoanRepayment::METHOD_PAYROLL,
                            'payroll_run_id' => $locked->id,
                            'note' => $locked->reference,
                            'created_by' => $actor->getKey(),
                        ]);
                        $loan->refreshStatus();
                    }
                }
            }

            $locked->update([
                'status' => PayrollRun::STATUS_PAID,
                'paid_by' => $actor->getKey(),
                'paid_at' => CarbonImmutable::parse($paidOn),
                'expense_id' => $expenseId,
            ]);

            return $locked->fresh();
        });
    }

    public function cancel(PayrollRun $run): PayrollRun
    {
        if (in_array($run->status, [PayrollRun::STATUS_PAID, PayrollRun::STATUS_CANCELLED], true)) {
            throw ValidationException::withMessages(['status' => 'A paid or cancelled payroll cannot be cancelled.']);
        }

        $run->update(['status' => PayrollRun::STATUS_CANCELLED]);

        return $run->fresh();
    }

    private function finalApprove(PayrollRun $run, User $actor): PayrollRun
    {
        $run = DB::connection('tenant')->transaction(function () use ($run, $actor) {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== PayrollRun::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'This payroll is not waiting for approval.']);
            }

            $locked->update(['status' => PayrollRun::STATUS_APPROVED, 'decided_by' => $actor->getKey(), 'decided_at' => now(), 'decision_reason' => null]);

            return $locked->fresh();
        });

        $this->notifyRequester($run, NotificationType::PAYROLL_RUN_APPROVED);

        return $run;
    }

    private function convert(float $amount, string $from, string $to, ?float $rate): float
    {
        if ($from === $to || $rate === null) {
            return $amount;
        }

        return $from === Tenant::CURRENCY_KHR ? $amount / $rate : $amount * $rate;
    }

    /** PR-2026-10, PR-2026-10-A / -B for 15-day runs; -2, -3... when one was cancelled. */
    private function reference(string $month, string $period): string
    {
        $base = 'PR-'.$month.match ($period) {
            PayrollRun::PERIOD_FIRST_HALF => '-A',
            PayrollRun::PERIOD_SECOND_HALF => '-B',
            default => '',
        };
        $taken = PayrollRun::query()->where('reference', 'like', $base.'%')->pluck('reference')->all();
        $reference = $base;
        for ($n = 2; in_array($reference, $taken, true); $n++) {
            $reference = "{$base}-{$n}";
        }

        return $reference;
    }

    private function assertEditable(PayrollRun $run): void
    {
        if (! $run->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Only a draft or rejected payroll can be changed.']);
        }
    }

    /** @param  Collection<int, User>  $recipients */
    public function notifyApprovers(PayrollRun $run, Collection $recipients): void
    {
        $this->notifications->notifyMany($recipients, NotificationType::PAYROLL_RUN_SUBMITTED, ['reference' => $run->reference], link: '/admin/payroll/approval');
    }

    /** @param  array<string, mixed>  $extra */
    private function notifyRequester(PayrollRun $run, string $type, array $extra = []): void
    {
        $requester = $run->requested_by !== null ? User::query()->find($run->requested_by) : null;
        if ($requester !== null) {
            $this->notifications->notifyMany(collect([$requester]), $type, ['reference' => $run->reference, ...$extra], link: '/admin/payroll/calculation');
        }
    }
}
