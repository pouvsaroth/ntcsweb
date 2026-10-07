<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Approvals\ApprovalFlow;
use App\Services\Payroll\PayrollRunService;
use App\Support\Accounting\AccountType;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * HRM > Payroll's runs and payslips — Payroll calculation (create, work
 * out, adjust, send for approval, pay), Payroll approval, Payslip and
 * Payroll history all read from here. See PayrollRunService.
 */
final class PayrollRunController extends Controller
{
    public function __construct(
        private readonly PayrollRunService $runs,
        private readonly ApprovalFlow $flow,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $query = PayrollRun::query()
            ->with(['requestedBy', 'decidedBy'])
            ->when($request->filled('year'), fn ($q) => $q->where('month', 'like', $request->integer('year').'-%'))
            ->when($request->filled('statuses'), fn ($q) => $q->whereIn('status', explode(',', (string) $request->string('statuses'))));

        $runs = ApiQuery::for($query, $request)
            ->filterable(['status', 'month', 'period'])
            ->sortable(['month', 'created_at'], default: '-month')
            ->maxPerPage(100)
            ->paginate();

        $totals = $this->totals($runs->getCollection()->modelKeys());
        $this->flow->attachProgress($runs->getCollection(), $request->user());

        return ApiResponse::success($runs->through(fn (PayrollRun $run) => $this->row($run, $totals->get($run->id, []), $request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);

        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'period' => ['required', Rule::in(PayrollRun::PERIODS)],
            'pay_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $run = $this->runs->create($data['month'], $data['period'], $data['pay_date'], $data['note'] ?? null, $request->user());

        return ApiResponse::created($this->detail($run, $request));
    }

    public function show(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('view', $payrollRun);

        return ApiResponse::success($this->detail($payrollRun, $request));
    }

    public function update(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('update', $payrollRun);
        abort_unless($payrollRun->isEditable(), 422, 'Only a draft or rejected payroll can be changed.');

        $payrollRun->update($request->validate([
            'pay_date' => ['sometimes', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]));

        return ApiResponse::success($this->detail($payrollRun, $request));
    }

    public function recalculate(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('update', $payrollRun);

        return ApiResponse::success($this->detail($this->runs->recalculate($payrollRun), $request));
    }

    public function submit(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('update', $payrollRun);

        return ApiResponse::success($this->detail($this->runs->submit($payrollRun, $request->user()), $request));
    }

    public function approve(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        return ApiResponse::success($this->detail($this->runs->approve($payrollRun, $request->user()), $request));
    }

    public function reject(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        return ApiResponse::success($this->detail($this->runs->reject($payrollRun, $data['reason'], $request->user()), $request));
    }

    public function pay(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('update', $payrollRun);

        $data = $request->validate([
            'expense_account_id' => ['required', 'integer', Rule::exists('tenant.accounts', 'id')],
            'cash_account_id' => ['required', 'integer', Rule::exists('tenant.accounts', 'id')],
            'paid_on' => ['required', 'date'],
        ]);

        $run = $this->runs->pay(
            $payrollRun,
            Account::query()->findOrFail($data['expense_account_id']),
            Account::query()->findOrFail($data['cash_account_id']),
            $data['paid_on'],
            $request->user(),
        );

        return ApiResponse::success($this->detail($run, $request));
    }

    public function cancel(Request $request, PayrollRun $payrollRun): JsonResponse
    {
        $this->authorize('update', $payrollRun);

        return ApiResponse::success($this->detail($this->runs->cancel($payrollRun), $request));
    }

    /** The accounts the Pay form offers — expense accounts, and cash/bank accounts to pay from. Salary (5100) first. */
    public function payAccounts(): JsonResponse
    {
        $this->authorize('create', PayrollRun::class);

        $accounts = Account::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'type', 'is_bank_or_cash']);

        return ApiResponse::success([
            'expense' => $accounts->where('type', AccountType::EXPENSE)->sortBy(fn (Account $a) => $a->code === '5100' ? '0' : $a->code)->values()->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name]),
            'cash' => $accounts->where('is_bank_or_cash', true)->values()->map(fn (Account $a) => ['id' => $a->id, 'code' => $a->code, 'name' => $a->name]),
        ]);
    }

    public function adjust(Request $request, Payslip $payslip): JsonResponse
    {
        $this->authorize('update', $payslip->run);

        $data = $request->validate([
            'adjustment' => ['required', 'numeric', 'min:-9999999999', 'max:9999999999'],
            'adjustment_note' => ['nullable', 'string', 'max:255'],
        ]);

        $this->runs->adjust($payslip, (float) $data['adjustment'], $data['adjustment_note'] ?? null);

        return ApiResponse::success($this->detail($payslip->run->fresh(), $request));
    }

    public function payslip(Payslip $payslip): JsonResponse
    {
        $this->authorize('view', $payslip->run);

        return ApiResponse::success($this->slipRow($payslip->load(['staff.position', 'staff.department', 'run']), full: true));
    }

    /** Payslips across runs — one staff member's history (`staff_id`), or every one of a year. Not cancelled runs. */
    public function payslips(Request $request): JsonResponse
    {
        $this->authorize('viewAny', PayrollRun::class);

        $query = Payslip::query()
            ->with(['staff', 'run'])
            ->whereHas('run', fn ($q) => $q->where('status', '!=', PayrollRun::STATUS_CANCELLED)
                ->when($request->filled('year'), fn ($r) => $r->where('month', 'like', $request->integer('year').'-%')))
            ->join('payroll_runs', 'payroll_runs.id', '=', 'payslips.payroll_run_id')
            ->select('payslips.*')
            ->orderByDesc('payroll_runs.period_end');

        $slips = ApiQuery::for($query, $request)
            ->filterable(['staff_id', 'payroll_run_id'])
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success($slips->through(fn (Payslip $slip) => $this->slipRow($slip)));
    }

    /** @return array<string, mixed> */
    private function detail(PayrollRun $run, Request $request): array
    {
        $run->load(['requestedBy', 'decidedBy', 'expense']);
        $this->flow->attachProgress([$run], $request->user());
        $slips = $run->payslips()->with('staff')->get()->sortBy(fn (Payslip $s) => $s->staff?->employee_code)->values();

        return [
            ...$this->row($run, $this->totals([$run->id])->get($run->id, []), $request),
            'payslips' => $slips->map(fn (Payslip $slip) => $this->slipRow($slip)),
            'expense' => $run->expense !== null ? ['id' => $run->expense->id, 'expense_number' => $run->expense->expense_number, 'amount' => $run->expense->amount] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function row(PayrollRun $run, array $totals, Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $run->id,
            'reference' => $run->reference,
            'month' => $run->month,
            'period' => $run->period,
            'period_start' => $run->period_start?->toDateString(),
            'period_end' => $run->period_end?->toDateString(),
            'pay_date' => $run->pay_date?->toDateString(),
            'status' => $run->status,
            'khr_per_usd' => $run->khr_per_usd,
            'note' => $run->note,
            'calculated_at' => $run->calculated_at?->toIso8601String(),
            'requested_by' => $run->requestedBy?->name,
            'submitted_at' => $run->submitted_at?->toIso8601String(),
            'decided_by' => $run->decidedBy?->name,
            'decided_at' => $run->decided_at?->toIso8601String(),
            'decision_reason' => $run->decision_reason,
            'paid_at' => $run->paid_at?->toDateString(),
            'totals' => array_values($totals),
            'approval_flow' => $run->relationLoaded('approvalFlow') ? $run->getRelation('approvalFlow') : null,
            'can_decide' => $run->status === PayrollRun::STATUS_PENDING && $this->flow->mayDecide($run, $user, 'approve'),
        ];
    }

    /** @return array<string, mixed> */
    private function slipRow(Payslip $slip, bool $full = false): array
    {
        $staff = $slip->staff;

        return [
            'id' => $slip->id,
            'payroll_run_id' => $slip->payroll_run_id,
            'run' => $slip->relationLoaded('run') && $slip->run !== null ? [
                'id' => $slip->run->id,
                'reference' => $slip->run->reference,
                'period' => $slip->run->period,
                'period_start' => $slip->run->period_start?->toDateString(),
                'period_end' => $slip->run->period_end?->toDateString(),
                'pay_date' => $slip->run->pay_date?->toDateString(),
                'status' => $slip->run->status,
            ] : null,
            'staff' => $staff !== null ? [
                'id' => $staff->id,
                'name' => $staff->fullName(),
                'employee_code' => $staff->employee_code,
                'position' => $full ? $staff->position?->name : null,
                'department' => $full ? $staff->department?->name : null,
            ] : null,
            'currency' => $slip->currency,
            ...collect(Payslip::AMOUNTS)->mapWithKeys(fn (string $column) => [$column => $slip->{$column}])->all(),
            'monthly_basic' => $slip->monthly_basic,
            'adjustment_note' => $slip->adjustment_note,
            'lines' => $slip->lines,
            'details' => $full ? $slip->details : null,
            'payment_method' => $slip->payment_method,
            'bank_name' => $slip->bank_name,
            'bank_account_name' => $slip->bank_account_name,
            'bank_account_number' => $slip->bank_account_number,
        ];
    }

    /**
     * Each run's totals per currency.
     *
     * @param  list<int>  $runIds
     * @return Collection<int, array<string, array<string, mixed>>>
     */
    private function totals(array $runIds): Collection
    {
        $sums = collect(Payslip::AMOUNTS)->map(fn (string $c) => "SUM({$c}) as {$c}")->implode(', ');

        return Payslip::query()
            ->whereIn('payroll_run_id', $runIds)
            ->selectRaw("payroll_run_id, currency, COUNT(*) as staff_count, {$sums}")
            ->groupBy('payroll_run_id', 'currency')
            ->get()
            ->groupBy('payroll_run_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [$row->currency => [
                'currency' => $row->currency,
                'staff_count' => (int) $row->staff_count,
                ...collect(Payslip::AMOUNTS)->mapWithKeys(fn (string $c) => [$c => round((float) $row->getAttribute($c), 2)])->all(),
            ]])->all());
    }
}
