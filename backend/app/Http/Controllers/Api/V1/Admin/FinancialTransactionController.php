<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAdjustmentRequest;
use App\Http\Requests\Api\V1\Admin\StoreTransferRequest;
use App\Http\Resources\FinancialTransactionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Services\Accounting\FinancialTransactionService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class FinancialTransactionController extends Controller
{
    public function __construct(private readonly FinancialTransactionService $transactions) {}

    /** The general ledger — every posting, of every type. See AccountingReportController for aggregated views. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FinancialTransaction::class);

        $query = FinancialTransaction::query()->with(['debitAccount', 'creditAccount', 'createdBy']);

        if ($request->filled('date_from')) {
            $query->whereDate('transaction_date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('transaction_date', '<=', $request->string('date_to')->toString());
        }

        $transactions = ApiQuery::for($query, $request)
            ->searchable('transaction_number', 'description')
            ->filterable(['type', 'status', 'debit_account_id', 'credit_account_id'])
            ->sortable(['transaction_date', 'amount', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(FinancialTransactionResource::collection($transactions));
    }

    public function show(FinancialTransaction $financialTransaction): JsonResponse
    {
        $this->authorize('view', $financialTransaction);

        return ApiResponse::success(new FinancialTransactionResource(
            $financialTransaction->load(['debitAccount', 'creditAccount', 'createdBy'])
        ));
    }

    public function transfer(StoreTransferRequest $request): JsonResponse
    {
        $from = Account::query()->findOrFail($request->validated('from_account_id'));
        $to = Account::query()->findOrFail($request->validated('to_account_id'));

        $transaction = $this->transactions->createTransfer(
            $from,
            $to,
            (float) $request->validated('amount'),
            $request->validated('date') ?? now()->toDateString(),
            $request->validated('description'),
            $request->user(),
        );

        return ApiResponse::created(new FinancialTransactionResource($transaction->load(['debitAccount', 'creditAccount'])));
    }

    public function adjustment(StoreAdjustmentRequest $request): JsonResponse
    {
        $debit = Account::query()->findOrFail($request->validated('debit_account_id'));
        $credit = Account::query()->findOrFail($request->validated('credit_account_id'));

        $transaction = $this->transactions->createAdjustment(
            $debit,
            $credit,
            (float) $request->validated('amount'),
            $request->validated('date') ?? now()->toDateString(),
            $request->validated('description'),
            $request->user(),
        );

        return ApiResponse::created(new FinancialTransactionResource($transaction->load(['debitAccount', 'creditAccount'])));
    }
}
