<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\Account;
use App\Models\Product;
use App\Models\Tenant;
use App\Support\Billing\ProductType;

/**
 * Which Revenue account a sale of a given Product posts to — checked in
 * order so every Product, including ones created before Accounting existed,
 * resolves to something sensible with zero manual setup:
 *
 *   1. Product.revenue_account_id, if the admin configured one explicitly.
 *   2. A built-in ProductType -> account code mapping (matches the codes
 *      ChartOfAccountsSeeder creates by default — an admin who renumbered
 *      their chart just overrides step 1 per-product instead).
 *   3. The tenant's configured default revenue account
 *      (tenants.settings->accounting->default_revenue_account_id).
 *
 * Returns null only if none of the three resolve — at that point the caller
 * (FinancialTransactionService::recognizeIncomeForPayment) skips posting for
 * that portion rather than blocking the underlying payment.
 */
final class RevenueAccountResolver
{
    private const TYPE_ACCOUNT_CODES = [
        ProductType::COURSE_FEE => '4100',
        ProductType::BOOK => '4200',
        ProductType::T_SHIRT => '4300',
        ProductType::UNIFORM => '4400',
        ProductType::CERTIFICATE => '4500',
        ProductType::OTHER => '4900',
    ];

    public function forProduct(Product $product, Tenant $tenant): ?Account
    {
        if ($product->revenue_account_id !== null) {
            $account = $product->revenueAccount;

            if ($account !== null) {
                return $account;
            }
        }

        $code = self::TYPE_ACCOUNT_CODES[$product->type] ?? null;

        if ($code !== null) {
            $account = Account::query()->where('code', $code)->first();

            if ($account !== null) {
                return $account;
            }
        }

        return $this->defaultRevenueAccount($tenant);
    }

    public function defaultRevenueAccount(Tenant $tenant): ?Account
    {
        $id = $tenant->setting('accounting.default_revenue_account_id');

        return $id !== null ? Account::query()->find($id) : null;
    }
}
