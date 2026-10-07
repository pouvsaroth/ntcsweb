<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\StaffLoanRepayment;
use App\Models\StaffSalary;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * HRM > Payroll > Loan/advance — money lent to a staff member, in their
 * salary currency, paid back by a fixed installment from each payroll (stage
 * 3) or in cash here. A loan settles itself once paid back; one can be
 * cancelled (the rest written off), or deleted while nothing is repaid.
 */
final class StaffLoanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffLoan::class);

        $loans = ApiQuery::for(StaffLoan::query()->with('staff')->withSum('repayments', 'amount'), $request)
            ->filterable(['status', 'staff_id', 'type'])
            ->sortable(['issued_on'], default: '-issued_on')
            ->maxPerPage(100)
            ->paginate();

        return ApiResponse::success($loans->through(fn (StaffLoan $loan) => $this->row($loan)));
    }

    public function show(StaffLoan $staffLoan): JsonResponse
    {
        $this->authorize('view', $staffLoan);

        return ApiResponse::success($this->row($staffLoan->load(['staff', 'repayments'])->loadSum('repayments', 'amount'), withRepayments: true));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', StaffLoan::class);

        $data = $request->validate([
            'staff_id' => ['required', 'integer', Rule::exists('tenant.staff', 'id')],
            'type' => ['required', Rule::in(StaffLoan::TYPES)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'issued_on' => ['required', 'date'],
            'installment_amount' => ['required', 'numeric', 'gt:0', 'lte:amount'],
            'first_deduction_on' => ['required', 'date', 'after_or_equal:issued_on'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        // Paid back from their pay, so it's in their salary's currency.
        $currency = StaffSalary::query()->where('staff_id', $data['staff_id'])->effectiveOn($data['issued_on'])->value('currency')
            ?? StaffSalary::query()->where('staff_id', $data['staff_id'])->orderBy('effective_from')->value('currency');
        if ($currency === null) {
            throw ValidationException::withMessages(['staff_id' => 'Set this staff member\'s basic salary first — the loan is paid back from it, in its currency.']);
        }

        $loan = StaffLoan::query()->create([...$data, 'currency' => $currency, 'created_by' => $request->user()->getKey()]);

        return ApiResponse::created($this->row($loan->load('staff')->loadSum('repayments', 'amount')));
    }

    public function update(Request $request, StaffLoan $staffLoan): JsonResponse
    {
        $this->authorize('update', $staffLoan);
        $this->assertOpen($staffLoan);

        $data = $request->validate([
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:9999999999'],
            'installment_amount' => ['sometimes', 'numeric', 'gt:0'],
            'first_deduction_on' => ['sometimes', 'date'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        if (isset($data['amount']) && (float) $data['amount'] < $staffLoan->repaid()) {
            throw ValidationException::withMessages(['amount' => 'The amount cannot be less than what is already paid back.']);
        }
        if ((float) ($data['installment_amount'] ?? $staffLoan->installment_amount) > (float) ($data['amount'] ?? $staffLoan->amount)) {
            throw ValidationException::withMessages(['installment_amount' => 'The installment cannot be more than the amount.']);
        }

        $staffLoan->update($data);
        $staffLoan->refreshStatus();

        return ApiResponse::success($this->row($staffLoan->load('staff')->loadSum('repayments', 'amount')));
    }

    /** Stops paying it back — what's left is written off. */
    public function cancel(StaffLoan $staffLoan): JsonResponse
    {
        $this->authorize('update', $staffLoan);
        $this->assertOpen($staffLoan);

        $staffLoan->update(['status' => StaffLoan::STATUS_CANCELLED]);

        return ApiResponse::success($this->row($staffLoan->load('staff')->loadSum('repayments', 'amount')));
    }

    public function destroy(StaffLoan $staffLoan): JsonResponse
    {
        $this->authorize('delete', $staffLoan);

        if ($staffLoan->repayments()->exists()) {
            return ApiResponse::error('This loan has repayments and cannot be deleted. Cancel it instead.', 422);
        }

        $staffLoan->delete();

        return ApiResponse::noContent();
    }

    /** A cash repayment — up to what's still owed. */
    public function repay(Request $request, StaffLoan $staffLoan): JsonResponse
    {
        $this->authorize('update', $staffLoan);
        $this->assertOpen($staffLoan);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'paid_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::connection('tenant')->transaction(function () use ($request, $staffLoan, $data) {
            $loan = StaffLoan::query()->whereKey($staffLoan->id)->lockForUpdate()->firstOrFail();
            if ((float) $data['amount'] > $loan->balance()) {
                throw ValidationException::withMessages(['amount' => 'This is more than what is still owed.']);
            }

            $loan->repayments()->create([...$data, 'method' => StaffLoanRepayment::METHOD_CASH, 'created_by' => $request->user()->getKey()]);
            $loan->refreshStatus();
        });

        return ApiResponse::success($this->row($staffLoan->fresh()->load(['staff', 'repayments'])->loadSum('repayments', 'amount'), withRepayments: true));
    }

    /** Only a cash repayment entered here can be removed — a payroll one belongs to its payroll run. */
    public function destroyRepayment(StaffLoanRepayment $staffLoanRepayment): JsonResponse
    {
        $loan = $staffLoanRepayment->loan;
        $this->authorize('update', $loan);

        if ($staffLoanRepayment->method !== StaffLoanRepayment::METHOD_CASH) {
            return ApiResponse::error('This repayment was taken by a payroll and cannot be removed here.', 422);
        }

        $staffLoanRepayment->delete();
        $loan->refreshStatus();

        return ApiResponse::success($this->row($loan->fresh()->load(['staff', 'repayments'])->loadSum('repayments', 'amount'), withRepayments: true));
    }

    private function assertOpen(StaffLoan $loan): void
    {
        if ($loan->status === StaffLoan::STATUS_CANCELLED) {
            throw ValidationException::withMessages(['status' => 'This loan is cancelled.']);
        }
    }

    /** @return array<string, mixed> */
    private function row(StaffLoan $loan, bool $withRepayments = false): array
    {
        return [
            'id' => $loan->id,
            'staff' => $loan->staff !== null ? ['id' => $loan->staff->id, 'name' => $loan->staff->fullName(), 'employee_code' => $loan->staff->employee_code] : null,
            'type' => $loan->type,
            'amount' => $loan->amount,
            'currency' => $loan->currency,
            'issued_on' => $loan->issued_on?->toDateString(),
            'installment_amount' => $loan->installment_amount,
            'first_deduction_on' => $loan->first_deduction_on?->toDateString(),
            'status' => $loan->status,
            'reason' => $loan->reason,
            'repaid' => $loan->repaid(),
            'balance' => $loan->status === StaffLoan::STATUS_CANCELLED ? 0.0 : $loan->balance(),
            'repayments' => $withRepayments ? $loan->repayments->sortByDesc('paid_on')->map(fn (StaffLoanRepayment $r) => [
                'id' => $r->id,
                'amount' => $r->amount,
                'paid_on' => $r->paid_on?->toDateString(),
                'method' => $r->method,
                'note' => $r->note,
            ])->values() : null,
        ];
    }
}
