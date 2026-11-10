<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerLoanPaymentResource;
use App\Http\Resources\CustomerLoanResource;
use App\Http\Responses\ApiResponse;
use App\Models\CustomerLoan;
use App\Models\CustomerLoanPayment;
use App\Services\Borrowings\BorrowingSchedule;
use App\Services\Loan\CustomerLoanService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Loan > Owe: money lent to customers, paid back monthly from a plan
 * (none / flat / reducing interest, BorrowingSchedule) or flexibly. Each
 * payment is split into principal and interest; the ledger is kept in step
 * by CustomerLoanService.
 */
final class CustomerLoanController extends Controller
{
    private const RELATIONS = ['customer', 'cashAccount'];

    public function __construct(private readonly CustomerLoanService $loans) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerLoan::class);

        $query = CustomerLoan::query()->with(self::RELATIONS)->withSum('payments', 'principal')->withSum('payments', 'interest');

        // Search matches the loan number, reference, purpose and the customer's name, code or phone.
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('loan_no', 'ILIKE', $like)
                ->orWhere('reference', 'ILIKE', $like)
                ->orWhere('purpose', 'ILIKE', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like)->orWhere('phone', 'ILIKE', $like)));
        }

        $loans = ApiQuery::for($query, $request)
            ->filterable(['status', 'currency', 'loan_customer_id'])
            ->sortable(['lent_on', 'amount', 'created_at'], default: '-lent_on')
            ->paginate();

        return ApiResponse::success(CustomerLoanResource::collection($loans));
    }

    /** The plan for terms typed in the form, before saving. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CustomerLoan::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            'interest_type' => ['required', Rule::in(CustomerLoan::INTEREST_TYPES)],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['nullable', Rule::in(CustomerLoan::INTEREST_PERIODS)],
            'term_months' => ['required', 'integer', 'min:1', 'max:600'],
            'first_payment_on' => ['required', 'date'],
        ]);

        $schedule = BorrowingSchedule::make(
            (float) $data['amount'],
            $data['currency'],
            $data['interest_type'],
            (float) ($data['interest_rate'] ?? 0),
            $data['interest_period'] ?? 'month',
            (int) $data['term_months'],
            Carbon::parse($data['first_payment_on']),
        );

        return ApiResponse::success(['schedule' => $schedule, 'totals' => BorrowingSchedule::totals($schedule)]);
    }

    /**
     * Cards on the Owe page (and the dashboard later), per currency (USD and
     * KHR never added together): still owed, late, due in 30 days, and
     * interest received this year.
     */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', CustomerLoan::class);

        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();
        $owed = $late = $dueSoon = [];
        $lateLoans = 0;

        $active = CustomerLoan::query()->where('status', 'active')->withSum('payments', 'principal')->withSum('payments', 'interest')->get();
        foreach ($active as $loan) {
            $c = $loan->currency;
            $paid = (float) $loan->payments_sum_principal;
            $owed[$c] = ($owed[$c] ?? 0) + (float) $loan->amount - $paid;
            $hasLate = false;
            foreach (BorrowingSchedule::withPayments(BorrowingSchedule::for($loan), $paid, $today, (float) $loan->payments_sum_interest) as $row) {
                if ($row['paid']) {
                    continue;
                }
                $amount = $row['due_principal'] + $row['interest'];
                if ($row['late']) {
                    $late[$c] = ($late[$c] ?? 0) + $amount;
                    $hasLate = true;
                } elseif ($row['due_on'] <= $soon) {
                    $dueSoon[$c] = ($dueSoon[$c] ?? 0) + $amount;
                }
            }
            $lateLoans += $hasLate ? 1 : 0;
        }

        $interest = CustomerLoanPayment::query()
            ->join('customer_loans', 'customer_loans.id', '=', 'customer_loan_payments.customer_loan_id')
            ->whereBetween('customer_loan_payments.paid_on', [now()->startOfYear()->toDateString(), $today])
            ->groupBy('customer_loans.currency')
            ->selectRaw('customer_loans.currency as currency, SUM(customer_loan_payments.interest) as total')
            ->pluck('total', 'currency');

        $rows = [];
        foreach (['USD', 'KHR'] as $c) {
            $rows[] = [
                'currency' => $c,
                'owed' => round($owed[$c] ?? 0, 2),
                'late' => round($late[$c] ?? 0, 2),
                'due_soon' => round($dueSoon[$c] ?? 0, 2),
                'interest_this_year' => round((float) ($interest[$c] ?? 0), 2),
                'loans' => $active->where('currency', $c)->count(),
            ];
        }

        return ApiResponse::success(['currencies' => $rows, 'late_loans' => $lateLoans]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', CustomerLoan::class);

        $data = $this->normalise($request->validate($this->rules(required: true)));
        $loan = $this->loans->create($data, $request->user());

        return ApiResponse::created($this->full($loan));
    }

    public function show(CustomerLoan $customerLoan): JsonResponse
    {
        $this->authorize('view', $customerLoan);

        return ApiResponse::success($this->full($customerLoan));
    }

    public function update(Request $request, CustomerLoan $customerLoan): JsonResponse
    {
        $this->authorize('update', $customerLoan);
        $this->assertActive($customerLoan);

        $data = $this->normalise($request->validate($this->rules(required: false)), $customerLoan);
        $this->loans->update($customerLoan, $data, $request->user());

        return ApiResponse::success($this->full($customerLoan));
    }

    public function cancel(Request $request, CustomerLoan $customerLoan): JsonResponse
    {
        $this->authorize('update', $customerLoan);
        $this->assertActive($customerLoan);

        $this->loans->cancel($customerLoan, $request->user());

        return ApiResponse::success($this->full($customerLoan));
    }

    public function destroy(Request $request, CustomerLoan $customerLoan): JsonResponse
    {
        $this->authorize('delete', $customerLoan);

        $this->loans->delete($customerLoan, $request->user());

        return ApiResponse::noContent();
    }

    /** A payment from the customer: principal and / or interest. */
    public function collect(Request $request, CustomerLoan $customerLoan): JsonResponse
    {
        $this->authorize('collect', $customerLoan);

        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'principal' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'interest' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cash_account_id' => ['required', 'integer', $this->cashAccountRule()],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $payment = $this->loans->collect($customerLoan, $data, $request->user());

        return ApiResponse::created(new CustomerLoanPaymentResource($payment->load(['cashAccount', 'creator'])));
    }

    public function removePayment(Request $request, CustomerLoanPayment $payment): JsonResponse
    {
        $loan = CustomerLoan::query()->findOrFail($payment->customer_loan_id);
        $this->authorize('delete', $loan);

        $this->loans->removePayment($payment, $request->user());

        return ApiResponse::success($this->full($loan));
    }

    private function full(CustomerLoan $loan): CustomerLoanResource
    {
        $loan->refresh()->load([...self::RELATIONS, 'payments' => fn ($q) => $q->with(['cashAccount', 'creator'])->orderBy('paid_on')->orderBy('id')]);

        return (new CustomerLoanResource($loan))->withSchedule();
    }

    private function assertActive(CustomerLoan $loan): void
    {
        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('Only an active loan can be changed.')]);
        }
    }

    /**
     * No interest = rate 0. Without months the loan is flexible (no plan,
     * no first payment date).
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, ?CustomerLoan $loan = null): array
    {
        $type = $data['interest_type'] ?? $loan?->interest_type ?? 'none';
        if ($type === 'none') {
            $data['interest_rate'] = 0;
        }
        if (array_key_exists('term_months', $data) && ! $data['term_months']) {
            $data['term_months'] = null;
            $data['first_payment_on'] = null;
        }

        return $data;
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'loan_customer_id' => [...$sometimes, 'required', 'integer', Rule::exists('tenant.loan_customers', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount' => [...$sometimes, 'required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => [...$sometimes, 'required', Rule::in(['USD', 'KHR'])],
            'lent_on' => [...$sometimes, 'required', 'date'],
            'cash_account_id' => ['nullable', 'integer', $this->cashAccountRule()],
            'interest_type' => [...$sometimes, 'required', Rule::in(CustomerLoan::INTEREST_TYPES)],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['nullable', Rule::in(CustomerLoan::INTEREST_PERIODS)],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'first_payment_on' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function cashAccountRule(): Exists
    {
        return Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true);
    }
}
