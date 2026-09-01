<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\UpdateAccountingSettingsRequest;
use App\Http\Responses\ApiResponse;
use App\Models\Tenant;
use App\Services\Accounting\AccountingNumberGenerator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Singleton, same shape as GeneralSettingsController — where every
 * Payment/Expense resolves its default Cash/Bank and Revenue accounts from
 * (see RevenueAccountResolver/FinancialTransactionService::resolveCashAccount()).
 * ChartOfAccountsSeeder fills these in with sensible defaults on first run;
 * this is where a school changes them.
 */
final class AccountingSettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AccountingNumberGenerator $numbers,
    ) {}

    public function show(): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $this->authorize('view', $tenant);

        return ApiResponse::success($this->payload($tenant));
    }

    public function update(UpdateAccountingSettingsRequest $request): JsonResponse
    {
        $tenant = $this->context->getOrFail();
        $tenant->refresh();

        $current = $tenant->setting('accounting', []);
        $topLevel = $tenant->settings ?? [];

        $tenant->update([
            'settings' => [
                ...$topLevel,
                'expense_prefix' => $request->validated('expense_prefix') ?? $tenant->setting('expense_prefix'),
                'transaction_prefix' => $request->validated('transaction_prefix') ?? $tenant->setting('transaction_prefix'),
                'transfer_prefix' => $request->validated('transfer_prefix') ?? $tenant->setting('transfer_prefix'),
                'accounting' => [
                    ...$current,
                    'default_cash_account_id' => $request->validated('default_cash_account_id') ?? ($current['default_cash_account_id'] ?? null),
                    'default_revenue_account_id' => $request->validated('default_revenue_account_id') ?? ($current['default_revenue_account_id'] ?? null),
                    'default_expense_payment_account_id' => $request->validated('default_expense_payment_account_id') ?? ($current['default_expense_payment_account_id'] ?? null),
                    'payment_method_accounts' => $request->validated('payment_method_accounts') ?? ($current['payment_method_accounts'] ?? []),
                ],
            ],
        ]);

        return ApiResponse::success($this->payload($tenant->fresh()));
    }

    private function payload(Tenant $tenant): array
    {
        return [
            'default_cash_account_id' => $tenant->setting('accounting.default_cash_account_id'),
            'default_revenue_account_id' => $tenant->setting('accounting.default_revenue_account_id'),
            'default_expense_payment_account_id' => $tenant->setting('accounting.default_expense_payment_account_id'),
            'payment_method_accounts' => $tenant->setting('accounting.payment_method_accounts', []),
            'expense_prefix' => $this->numbers->expensePrefixFor($tenant),
            'transaction_prefix' => $this->numbers->transactionPrefixFor($tenant),
            'transfer_prefix' => $this->numbers->transferPrefixFor($tenant),
        ];
    }
}
