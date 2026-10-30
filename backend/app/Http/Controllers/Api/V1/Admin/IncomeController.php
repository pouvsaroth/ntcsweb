<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreIncomeRequest;
use App\Http\Resources\FinancialTransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Income" here means every INCOME-type ledger posting — both the automatic
 * ones from PaymentService and the manual "other income" entries this
 * controller's store() creates (spec section 6 — a walk-in sale that never
 * went through Invoice/Payment). Both live in the same `financial_transactions`
 * table; this is just a type-filtered view of it, not a separate table.
 */
final class IncomeController extends Controller
{
    public function __construct(private readonly FinancialTransactionService $transactions) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()?->hasPermission(Permissions::INCOME_VIEW), 403);

        $query = FinancialTransaction::query()->ofType(TransactionType::INCOME)->with(['debitAccount', 'creditAccount']);

        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->string('date_to')->toString());
        }

        // For month ('YYYY-MM'): the month it belongs to, not the day money moved.
        $forMonth = $request->input('filter.for_month', $request->input('for_month'));
        if (is_string($forMonth) && $forMonth !== '') {
            validator(['for_month' => $forMonth], ['for_month' => ['date_format:Y-m']])->validate();
            $query->forMonth($forMonth);
        }

        $income = ApiQuery::for($query, $request)
            ->searchable('transaction_number', 'description')
            ->filterable(['credit_account_id', 'reference_type', 'currency'])
            ->sortable(['transaction_date', 'for_month', 'amount', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(FinancialTransactionResource::collection($income));
    }

    public function store(StoreIncomeRequest $request): JsonResponse
    {
        $revenueAccount = Account::query()->findOrFail($request->validated('revenue_account_id'));
        $cashAccount = Account::query()->findOrFail($request->validated('cash_account_id'));

        $transaction = $this->transactions->createManualIncome(
            $revenueAccount,
            $cashAccount,
            (float) $request->validated('amount'),
            $request->validated('date') ?? now()->toDateString(),
            $request->validated('description'),
            $request->user(),
            $request->validated('currency'),
            $request->validated('for_month'),
        );

        return ApiResponse::created(new FinancialTransactionResource($transaction->load(['debitAccount', 'creditAccount'])));
    }
}
