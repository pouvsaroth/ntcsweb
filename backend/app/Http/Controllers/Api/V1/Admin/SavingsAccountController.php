<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavingsAccountResource;
use App\Http\Resources\SavingsEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\SavingsAccount;
use App\Models\SavingsTransaction;
use App\Services\Loan\SavingsService;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Loan > Savings: one account per customer per currency. Deposits and
 * withdrawals (each with a date and remark) post to the ledger through
 * SavingsService; the statement lists them with the running balance.
 */
final class SavingsAccountController extends Controller
{
    public function __construct(private readonly SavingsService $savings) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SavingsAccount::class);

        $query = $this->withTotals(SavingsAccount::query()->with('customer'));

        // Search matches the account number and the customer's name, code or phone.
        $term = trim((string) $request->input('search', ''));
        if ($term !== '') {
            $like = '%'.addcslashes($term, '%_\\').'%';
            $query->where(fn ($q) => $q->where('account_no', 'ILIKE', $like)
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'ILIKE', $like)->orWhere('code', 'ILIKE', $like)->orWhere('phone', 'ILIKE', $like)));
        }

        $accounts = ApiQuery::for($query, $request)
            ->filterable(['status', 'currency', 'loan_customer_id'])
            ->sortable(['account_no', 'opened_on', 'created_at'], default: '-opened_on')
            ->paginate();

        return ApiResponse::success(SavingsAccountResource::collection($accounts));
    }

    /** Open accounts and what is kept, per currency (never summed together). */
    public function summary(): JsonResponse
    {
        $this->authorize('viewAny', SavingsAccount::class);

        $rows = $this->withTotals(SavingsAccount::query()->where('status', 'open'))->get();
        $summary = [];
        foreach (['USD', 'KHR'] as $currency) {
            $mine = $rows->where('currency', $currency);
            $summary[] = [
                'currency' => $currency,
                'accounts' => $mine->count(),
                'balance' => round($mine->sum(fn ($a) => (float) $a->total_in - (float) $a->total_out), 2),
            ];
        }

        return ApiResponse::success($summary);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', SavingsAccount::class);

        $data = $request->validate([
            'loan_customer_id' => ['required', 'integer', Rule::exists('tenant.loan_customers', 'id')->where('is_active', true)],
            'currency' => ['required', Rule::in(['USD', 'KHR'])],
            'opened_on' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // An optional first deposit, made on the opening date.
            'first_deposit' => ['nullable', 'numeric', 'gt:0', 'max:9999999999'],
            'cash_account_id' => ['required_with:first_deposit', 'nullable', 'integer', $this->cashAccountRule()],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        if (SavingsAccount::query()->where('loan_customer_id', $data['loan_customer_id'])->where('currency', $data['currency'])->exists()) {
            throw ValidationException::withMessages(['currency' => __('This customer already has a :currency savings account.', ['currency' => $data['currency']])]);
        }

        $first = isset($data['first_deposit']) ? ['amount' => $data['first_deposit'], 'cash_account_id' => (int) $data['cash_account_id'], 'remark' => $data['remark'] ?? null] : null;
        $account = $this->savings->open([
            'loan_customer_id' => (int) $data['loan_customer_id'],
            'currency' => $data['currency'],
            'opened_on' => $data['opened_on'],
            'notes' => $data['notes'] ?? null,
        ], $first, $request->user());

        return ApiResponse::created($this->full($account));
    }

    public function show(SavingsAccount $savingsAccount): JsonResponse
    {
        $this->authorize('view', $savingsAccount);

        return ApiResponse::success($this->full($savingsAccount));
    }

    /** Notes, opening date, and open / closed (closing needs a zero balance). */
    public function update(Request $request, SavingsAccount $savingsAccount): JsonResponse
    {
        $this->authorize('update', $savingsAccount);

        $data = $request->validate([
            'opened_on' => ['sometimes', 'required', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'status' => ['sometimes', Rule::in(SavingsAccount::STATUSES)],
        ]);

        if (($data['status'] ?? null) === 'closed' && abs($this->savings->balance($savingsAccount)) > 0.001) {
            throw ValidationException::withMessages(['status' => __('Withdraw the whole balance before closing the account.')]);
        }
        if (isset($data['opened_on']) && $savingsAccount->entries()->where('transacted_on', '<', $data['opened_on'])->exists()) {
            throw ValidationException::withMessages(['opened_on' => __('There are entries before this date.')]);
        }

        $savingsAccount->update($data);

        return ApiResponse::success($this->full($savingsAccount));
    }

    /** Deletes the account with its deposits and withdrawals; their ledger entries are reversed. */
    public function destroy(Request $request, SavingsAccount $savingsAccount): JsonResponse
    {
        $this->authorize('delete', $savingsAccount);

        $this->savings->deleteAccount($savingsAccount, $request->user());

        return ApiResponse::noContent();
    }

    /** A deposit or a withdrawal, with its date and remark. */
    public function record(Request $request, SavingsAccount $savingsAccount): JsonResponse
    {
        $this->authorize('record', $savingsAccount);

        $data = $request->validate([
            'type' => ['required', Rule::in(SavingsTransaction::RECORDABLE)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999'],
            'transacted_on' => ['required', 'date'],
            'cash_account_id' => ['required', 'integer', $this->cashAccountRule()],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $entry = $this->savings->record($savingsAccount, $data, $request->user());

        return ApiResponse::created(new SavingsEntryResource($entry->load(['cashAccount', 'creator'])));
    }

    public function removeEntry(Request $request, SavingsTransaction $entry): JsonResponse
    {
        $account = SavingsAccount::query()->findOrFail($entry->savings_account_id);
        $this->authorize('delete', $account);

        $this->savings->remove($entry, $request->user());

        return ApiResponse::success($this->full($account));
    }

    private function full(SavingsAccount $account): SavingsAccountResource
    {
        $fresh = $this->withTotals(SavingsAccount::query()->with('customer'))->findOrFail($account->id);
        $resource = new SavingsAccountResource($fresh);
        $resource->statement = $this->savings->statement($fresh);

        return $resource;
    }

    /**
     * @param  Builder<SavingsAccount>  $query
     * @return Builder<SavingsAccount>
     */
    private function withTotals(Builder $query): Builder
    {
        return $query
            ->withSum(['entries as total_in' => fn ($q) => $q->where('type', '!=', SavingsTransaction::WITHDRAWAL)], 'amount')
            ->withSum(['entries as total_out' => fn ($q) => $q->where('type', SavingsTransaction::WITHDRAWAL)], 'amount')
            ->withCount('entries')
            ->withMax('entries as last_entry_on', 'transacted_on');
    }

    private function cashAccountRule(): Exists
    {
        return Rule::exists('tenant.accounts', 'id')->where('is_bank_or_cash', true)->where('is_active', true);
    }
}
