<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Account;
use App\Support\Accounting\AccountType;
use App\Support\Billing\ProductType;

/**
 * A minimal Chart of Accounts for a test tenant — real tests don't run
 * ChartOfAccountsSeeder (that's covered by its own seeder test / the console
 * command), they build just the handful of accounts a given test needs and
 * wire the tenant's `accounting` settings to point at them, mirroring
 * exactly what ChartOfAccountsSeeder does for a real school.
 */
trait HasChartOfAccounts
{
    protected Account $cashAccount;

    protected Account $courseFeesAccount;

    protected Account $bookSalesAccount;

    protected Account $tshirtSalesAccount;

    protected Account $otherIncomeAccount;

    protected Account $electricityAccount;

    /** @param array<string,mixed> $accountingSettings */
    protected function setUpChartOfAccounts(array $accountingSettings = []): void
    {
        $this->cashAccount = Account::factory()->forTenant($this->tenant)->bankOrCash()->create(['code' => '1100', 'name' => 'Cash']);
        $this->courseFeesAccount = Account::factory()->forTenant($this->tenant)->type(AccountType::REVENUE)->create(['code' => '4100', 'name' => 'Course Fees']);
        $this->bookSalesAccount = Account::factory()->forTenant($this->tenant)->type(AccountType::REVENUE)->create(['code' => '4200', 'name' => 'Book Sales']);
        $this->tshirtSalesAccount = Account::factory()->forTenant($this->tenant)->type(AccountType::REVENUE)->create(['code' => '4300', 'name' => 'T-Shirt Sales']);
        $this->otherIncomeAccount = Account::factory()->forTenant($this->tenant)->type(AccountType::REVENUE)->create(['code' => '4900', 'name' => 'Other Income']);
        $this->electricityAccount = Account::factory()->forTenant($this->tenant)->type(AccountType::EXPENSE)->create(['code' => '5300', 'name' => 'Electricity']);

        $this->tenant->update([
            'settings' => [
                ...($this->tenant->settings ?? []),
                'accounting' => [
                    'default_cash_account_id' => $this->cashAccount->id,
                    'default_revenue_account_id' => $this->otherIncomeAccount->id,
                    'default_expense_payment_account_id' => $this->cashAccount->id,
                    'payment_method_accounts' => [],
                    ...$accountingSettings,
                ],
            ],
        ]);

        $this->tenant->refresh();

        // AuthenticatedUserTenantResolver reads $user->tenant on every request
        // and Laravel's actingAs() keeps reusing this exact $this->admin
        // instance across every postJson() call in a test — so its cached
        // `tenant` relation would otherwise still point at the pre-seeding
        // settings for the rest of the test. Re-pointing it here is a
        // test-only concern; a real request always loads the user fresh.
        if (isset($this->admin)) {
            $this->admin->setRelation('tenant', $this->tenant);
        }
    }

    /** @return array<string,string> ProductType => account code, matching RevenueAccountResolver's own built-in mapping. */
    protected function productTypeAccounts(): array
    {
        return [
            ProductType::COURSE_FEE => $this->courseFeesAccount->code,
            ProductType::BOOK => $this->bookSalesAccount->code,
            ProductType::T_SHIRT => $this->tshirtSalesAccount->code,
        ];
    }
}
