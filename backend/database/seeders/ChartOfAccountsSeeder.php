<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Tenant;
use App\Support\Accounting\AccountType;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Idempotent — safe to run on every deploy, same convention as
 * RolePermissionSeeder. Gives every tenant a sensible starting Chart of
 * Accounts (fully editable afterward — nothing here is hard-coded into the
 * application logic itself, see RevenueAccountResolver) and points the
 * tenant's accounting settings at sensible defaults so Payment -> Revenue
 * recognition works immediately with zero manual setup.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /** @var list<array{code:string,name:string,type:string,parent:string|null,bank?:bool}> */
    private const TREE = [
        ['code' => '1000', 'name' => 'Assets', 'type' => AccountType::ASSET, 'parent' => null],
        ['code' => '1100', 'name' => 'Cash', 'type' => AccountType::ASSET, 'parent' => '1000', 'bank' => true],
        ['code' => '1200', 'name' => 'Bank', 'type' => AccountType::ASSET, 'parent' => '1000', 'bank' => true],

        ['code' => '2000', 'name' => 'Liabilities', 'type' => AccountType::LIABILITY, 'parent' => null],

        ['code' => '3000', 'name' => 'Equity', 'type' => AccountType::EQUITY, 'parent' => null],

        ['code' => '4000', 'name' => 'Revenue', 'type' => AccountType::REVENUE, 'parent' => null],
        ['code' => '4100', 'name' => 'Course Fees', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4200', 'name' => 'Book Sales', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4300', 'name' => 'T-Shirt Sales', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4400', 'name' => 'Uniform Sales', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4500', 'name' => 'Certificate Fees', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4600', 'name' => 'Registration Fees', 'type' => AccountType::REVENUE, 'parent' => '4000'],
        ['code' => '4900', 'name' => 'Other Income', 'type' => AccountType::REVENUE, 'parent' => '4000'],

        ['code' => '5000', 'name' => 'Expenses', 'type' => AccountType::EXPENSE, 'parent' => null],
        ['code' => '5100', 'name' => 'Salary', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5200', 'name' => 'Rent', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5300', 'name' => 'Electricity', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5400', 'name' => 'Internet', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5500', 'name' => 'Office Supplies', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5600', 'name' => 'Maintenance', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5700', 'name' => 'Transportation', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5800', 'name' => 'Marketing', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
        ['code' => '5900', 'name' => 'Other Expenses', 'type' => AccountType::EXPENSE, 'parent' => '5000'],
    ];

    public function run(): void
    {
        Tenant::query()->withoutGlobalScopes()->chunkById(50, function ($tenants) {
            foreach ($tenants as $tenant) {
                $this->seedForTenant($tenant);
            }
        });
    }

    private function seedForTenant(Tenant $tenant): void
    {
        // BelongsToTenant auto-stamps tenant_id from ambient context on
        // create and scopes every read to it — runFor() is the documented
        // way a console command enters a specific tenant safely, so
        // `tenant_id` never needs to appear in a mass-assignment array here.
        app(TenantContext::class)->runFor($tenant, function () use ($tenant) {
            $ids = [];

            foreach (self::TREE as $row) {
                $account = Account::query()->firstOrCreate(
                    ['code' => $row['code']],
                    [
                        'name' => $row['name'],
                        'type' => $row['type'],
                        'parent_id' => $row['parent'] !== null ? ($ids[$row['parent']] ?? null) : null,
                        'is_bank_or_cash' => $row['bank'] ?? false,
                    ],
                );

                $ids[$row['code']] = $account->getKey();
            }

            if ($tenant->setting('accounting.default_cash_account_id') !== null) {
                return;
            }

            $tenant->update([
                'settings' => [
                    ...($tenant->settings ?? []),
                    'accounting' => [
                        'default_cash_account_id' => $ids['1100'],
                        'default_revenue_account_id' => $ids['4900'],
                        'default_expense_payment_account_id' => $ids['1100'],
                        'payment_method_accounts' => [],
                    ],
                ],
            ]);
        });
    }
}
