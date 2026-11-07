<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BorrowingRepaymentResource;
use App\Http\Resources\BorrowingResource;
use App\Http\Responses\ApiResponse;
use App\Models\Borrowing;
use App\Models\BorrowingRepayment;
use App\Models\Tenant;
use App\Services\Borrowings\BorrowingSchedule;
use App\Services\Borrowings\BorrowingService;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Accounting > Borrowings > Loans: money borrowed from a lender, for one
 * "Borrowed for" (Newtech, Flexi, Family...), with a monthly repayment plan
 * worked out from its terms (BorrowingSchedule). The contract file is kept
 * private and downloaded through this controller.
 */
final class BorrowingController extends Controller
{
    private const RELATIONS = ['lender', 'borrowedFor', 'cashAccount'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly BorrowingService $loans,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Borrowing::class);

        $query = Borrowing::query()->with(self::RELATIONS)->withSum('repayments', 'principal')->withSum('repayments', 'interest');

        // Search also matches the lender's name (ApiQuery searches own columns only).
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('reference', 'ILIKE', $like)
                ->orWhere('purpose', 'ILIKE', $like)
                ->orWhereHas('lender', fn ($l) => $l->where('name', 'ILIKE', $like)));
        }

        $loans = ApiQuery::for($query, $request)
            ->filterable(['status', 'lender_id', 'expense_entity_id', 'currency'])
            ->sortable(['received_on', 'amount', 'created_at'], default: '-received_on')
            ->paginate();

        return ApiResponse::success(BorrowingResource::collection($loans));
    }

    /** The plan for terms typed in the form, before saving. */
    public function preview(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Borrowing::class);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            'interest_type' => ['required', Rule::in(Borrowing::INTEREST_TYPES)],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['nullable', Rule::in(Borrowing::INTEREST_PERIODS)],
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

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Borrowing::class);

        $data = $this->normalise($request->validate($this->rules(required: true)));
        $loan = $this->loans->create($data, $request->user());

        return ApiResponse::created($this->full($loan));
    }

    public function show(Borrowing $borrowing): JsonResponse
    {
        $this->authorize('view', $borrowing);

        return ApiResponse::success($this->full($borrowing));
    }

    public function update(Request $request, Borrowing $borrowing): JsonResponse
    {
        $this->authorize('update', $borrowing);
        $this->assertActive($borrowing);

        $data = $this->normalise($request->validate($this->rules(required: false)), $borrowing);
        $this->loans->update($borrowing, $data, $request->user());

        return ApiResponse::success($this->full($borrowing));
    }

    public function cancel(Request $request, Borrowing $borrowing): JsonResponse
    {
        $this->authorize('update', $borrowing);
        $this->assertActive($borrowing);

        $this->loans->cancel($borrowing, $request->user());

        return ApiResponse::success($this->full($borrowing));
    }

    public function destroy(Request $request, Borrowing $borrowing): JsonResponse
    {
        $this->authorize('delete', $borrowing);

        $path = $borrowing->contract_path;
        $this->loans->delete($borrowing, $request->user());
        if ($path) {
            Storage::disk('local')->delete($path);
        }

        return ApiResponse::noContent();
    }

    public function uploadContract(Request $request, Borrowing $borrowing): JsonResponse
    {
        $this->authorize('update', $borrowing);

        $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx']]);

        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();
        $file = $request->file('file');
        $path = $file->store($tenant->storagePath('borrowing-contracts'), 'local');

        if ($borrowing->contract_path) {
            Storage::disk('local')->delete($borrowing->contract_path);
        }
        $borrowing->update(['contract_path' => $path, 'contract_name' => $file->getClientOriginalName()]);

        return ApiResponse::success($this->full($borrowing));
    }

    public function downloadContract(Borrowing $borrowing): StreamedResponse
    {
        $this->authorize('view', $borrowing);

        abort_unless($borrowing->contract_path && Storage::disk('local')->exists($borrowing->contract_path), 404);

        return Storage::disk('local')->download($borrowing->contract_path, $borrowing->contract_name ?? 'contract');
    }

    /**
     * Repayments tab: what is due in the next `days` (30) days and what is
     * late — the next unpaid month of every active loan — then the active
     * flexible loans, which have no due date.
     */
    public function due(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Borrowing::class);

        $until = now()->addDays(max(0, min(366, (int) $request->input('days', 30))))->toDateString();
        $rows = Borrowing::query()->where('status', 'active')->with(self::RELATIONS)->withSum('repayments', 'principal')->withSum('repayments', 'interest')->get()
            ->map(fn (Borrowing $loan) => (new BorrowingResource($loan))->resolve($request))
            ->filter(fn (array $loan) => $loan['flexible'] || ($loan['next_payment'] !== null && $loan['next_payment']['due_on'] <= $until))
            // Flexible loans (no fixed plan, paid any time) come after the dated ones.
            ->sortBy(fn (array $loan) => $loan['next_payment']['due_on'] ?? '9999-12-31')
            ->values();

        return ApiResponse::success($rows);
    }

    /**
     * Reports tab and the dashboard card. Amounts are per currency (USD and
     * KHR are never added together):
     *  - owed / late / due in 30 days / the next payment, over active loans;
     *  - by lender and by Borrowed for: borrowed, paid back, still owed
     *    (cancelled loans left out);
     *  - interest and principal paid between `from` and `to` (default: this
     *    year), by Borrowed for.
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Borrowing::class);

        $data = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = $data['from'] ?? now()->startOfYear()->toDateString();
        $to = $data['to'] ?? now()->toDateString();
        $today = now()->toDateString();
        $soon = now()->addDays(30)->toDateString();

        $loans = Borrowing::query()->where('status', '!=', 'cancelled')->with(['lender', 'borrowedFor'])->withSum('repayments', 'principal')->withSum('repayments', 'interest')->get();

        $owed = $late = $dueSoon = [];
        $lateLoans = 0;
        $next = null;
        $byLender = $byFor = [];
        $add = function (array &$bucket, string $key, array $base, Borrowing $loan, float $paidPrincipal, float $paidInterest): void {
            $bucket[$key] ??= [...$base, 'currency' => $loan->currency, 'loans' => 0, 'borrowed' => 0.0, 'paid_principal' => 0.0, 'paid_interest' => 0.0, 'outstanding' => 0.0];
            $bucket[$key]['loans']++;
            $bucket[$key]['borrowed'] += (float) $loan->amount;
            $bucket[$key]['paid_principal'] += $paidPrincipal;
            $bucket[$key]['paid_interest'] += $paidInterest;
            $bucket[$key]['outstanding'] += $loan->status === 'active' ? (float) $loan->amount - $paidPrincipal : 0.0;
        };

        foreach ($loans as $loan) {
            $paidPrincipal = (float) $loan->repayments_sum_principal;
            $paidInterest = (float) $loan->repayments_sum_interest;
            $c = $loan->currency;
            $add($byLender, "{$loan->lender_id}|{$c}", ['id' => $loan->lender_id, 'name' => $loan->lender?->name], $loan, $paidPrincipal, $paidInterest);
            $add($byFor, "{$loan->expense_entity_id}|{$c}", ['id' => $loan->expense_entity_id, 'name' => $loan->borrowedFor?->name], $loan, $paidPrincipal, $paidInterest);

            if ($loan->status !== 'active') {
                continue;
            }

            $owed[$c] = ($owed[$c] ?? 0) + (float) $loan->amount - $paidPrincipal;
            $rows = BorrowingSchedule::withPayments(BorrowingSchedule::for($loan), $paidPrincipal, $today);
            $hasLate = false;
            foreach ($rows as $row) {
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

            $first = collect($rows)->firstWhere('paid', false);
            if ($first && ($next === null || $first['due_on'] < $next['due_on'])) {
                $next = [
                    'loan_id' => $loan->id,
                    'lender' => $loan->lender?->name,
                    'borrowed_for' => $loan->borrowedFor?->name,
                    'due_on' => $first['due_on'],
                    'amount' => round($first['due_principal'] + $first['interest'], 2),
                    'currency' => $c,
                    'late' => $first['late'],
                ];
            }
        }

        $perCurrency = fn (array $values) => collect($values)->map(fn ($amount, $currency) => ['currency' => $currency, 'amount' => round($amount, 2)])->sortBy('currency')->values();
        $rounded = fn (array $bucket) => collect($bucket)->map(fn (array $row) => [
            ...$row,
            'borrowed' => round($row['borrowed'], 2),
            'paid_principal' => round($row['paid_principal'], 2),
            'paid_interest' => round($row['paid_interest'], 2),
            'outstanding' => round($row['outstanding'], 2),
        ])->sortByDesc('outstanding')->values();

        $paidInRange = BorrowingRepayment::query()
            ->join('borrowings', 'borrowings.id', '=', 'borrowing_repayments.borrowing_id')
            ->leftJoin('expense_entities', 'expense_entities.id', '=', 'borrowings.expense_entity_id')
            ->whereBetween('borrowing_repayments.paid_on', [$from, $to])
            ->groupBy('borrowings.expense_entity_id', 'expense_entities.name', 'borrowings.currency')
            ->orderBy('expense_entities.name')
            ->get([
                'borrowings.expense_entity_id as id',
                'expense_entities.name as name',
                'borrowings.currency as currency',
                DB::raw('SUM(borrowing_repayments.principal) as principal'),
                DB::raw('SUM(borrowing_repayments.interest) as interest'),
                DB::raw('COUNT(*) as payments'),
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'currency' => $row->currency,
                'principal' => round((float) $row->principal, 2),
                'interest' => round((float) $row->interest, 2),
                'payments' => (int) $row->payments,
            ]);

        return ApiResponse::success([
            'owed' => $perCurrency($owed),
            'late' => $perCurrency($late),
            'late_loans' => $lateLoans,
            'due_soon' => $perCurrency($dueSoon),
            'active_loans' => $loans->where('status', 'active')->count(),
            'next_payment' => $next,
            'by_lender' => $rounded($byLender),
            'by_borrowed_for' => $rounded($byFor),
            'paid' => ['from' => $from, 'to' => $to, 'rows' => $paidInRange],
        ]);
    }

    /** Repayments tab: every payment, newest first. */
    public function repayments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Borrowing::class);

        $query = BorrowingRepayment::query()->with(['borrowing.lender', 'borrowing.borrowedFor', 'cashAccount']);

        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('note', 'ILIKE', $like)
                ->orWhereHas('borrowing', fn ($b) => $b->where('reference', 'ILIKE', $like)
                    ->orWhereHas('lender', fn ($l) => $l->where('name', 'ILIKE', $like))));
        }
        foreach (['lender_id', 'expense_entity_id'] as $field) {
            $value = $request->input("filter.{$field}");
            if ($value !== null && $value !== '') {
                $query->whereHas('borrowing', fn ($b) => $b->where($field, (int) $value));
            }
        }
        if ($from = $request->input('filter.from')) {
            $query->whereDate('paid_on', '>=', $from);
        }
        if ($to = $request->input('filter.to')) {
            $query->whereDate('paid_on', '<=', $to);
        }

        $rows = ApiQuery::for($query, $request)
            ->filterable(['borrowing_id'])
            ->sortable(['paid_on', 'amount', 'created_at'], default: '-paid_on')
            ->paginate();

        return ApiResponse::success(BorrowingRepaymentResource::collection($rows));
    }

    public function repay(Request $request, Borrowing $borrowing): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::BORROWING_REPAYMENTS_CREATE), 403);

        $data = $request->validate([
            'paid_on' => ['required', 'date'],
            'principal' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'interest' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'cash_account_id' => ['required', 'integer', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->loans->repay($borrowing, $data, $request->user());

        return ApiResponse::created($this->full($borrowing->refresh()));
    }

    public function removeRepayment(Request $request, BorrowingRepayment $repayment): JsonResponse
    {
        abort_unless($request->user()->hasPermission(Permissions::BORROWING_REPAYMENTS_DELETE), 403);

        $loan = $this->loans->removeRepayment($repayment, $request->user());

        return ApiResponse::success($this->full($loan));
    }

    private function full(Borrowing $loan): BorrowingResource
    {
        $loan->load([...self::RELATIONS, 'repayments' => fn ($q) => $q->with('cashAccount')->orderByDesc('paid_on')->orderByDesc('id')]);

        return (new BorrowingResource($loan))->withSchedule();
    }

    /** @return array<string, list<mixed>> */
    private function rules(bool $required): array
    {
        $sometimes = $required ? [] : ['sometimes'];

        return [
            'lender_id' => [...$sometimes, 'required', 'integer', Rule::exists('tenant.lenders', 'id')->where('is_active', true)],
            'expense_entity_id' => [...$sometimes, 'required', 'integer', Rule::exists('tenant.expense_entities', 'id')->where('is_active', true)],
            'reference' => ['nullable', 'string', 'max:100'],
            'amount' => [...$sometimes, 'required', 'numeric', 'gt:0', 'max:9999999999'],
            'currency' => [...$sometimes, 'required', Rule::in(['USD', 'KHR'])],
            'received_on' => [...$sometimes, 'required', 'date'],
            'cash_account_id' => ['nullable', 'integer', Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true)],
            'interest_type' => [...$sometimes, 'required', Rule::in(Borrowing::INTEREST_TYPES)],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'interest_period' => ['nullable', Rule::in(Borrowing::INTEREST_PERIODS)],
            'term_months' => ['nullable', 'integer', 'min:1', 'max:600'],
            'first_payment_on' => ['nullable', 'date'],
            'purpose' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * No interest means a zero rate; the first payment can't come before the
     * money was received.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, ?Borrowing $loan = null): array
    {
        $type = $data['interest_type'] ?? $loan?->interest_type ?? 'none';
        if ($type === 'none') {
            $data['interest_rate'] = 0;
        } elseif (array_key_exists('interest_rate', $data) || $loan === null) {
            if ((float) ($data['interest_rate'] ?? 0) <= 0) {
                throw ValidationException::withMessages(['interest_rate' => __('Enter the interest rate.')]);
            }
        }
        if (array_key_exists('interest_period', $data) && $data['interest_period'] === null) {
            $data['interest_period'] = 'month';
        }

        $received = $data['received_on'] ?? $loan?->received_on?->toDateString();
        $first = $data['first_payment_on'] ?? $loan?->first_payment_on?->toDateString();
        if ($received && $first && $first < Carbon::parse($received)->toDateString()) {
            throw ValidationException::withMessages(['first_payment_on' => __('The first payment cannot be before the money was received.')]);
        }

        return $data;
    }

    private function assertActive(Borrowing $loan): void
    {
        if ($loan->status !== 'active') {
            throw ValidationException::withMessages(['status' => __('Only an active loan can be changed.')]);
        }
    }
}
