<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\FinancialTransaction;
use App\Models\User;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Account > Expense / Income: "For month" (paid today, for September) and currency. */
class ForMonthAndCurrencyTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_an_expense_keeps_its_for_month_and_currency_into_the_ledger(): void
    {
        $admin = $this->actingAsAdminWithPermissions([
            Permissions::EXPENSE_VIEW, Permissions::EXPENSE_CREATE, Permissions::EXPENSE_APPROVE, Permissions::EXPENSE_PAY,
        ]);
        $this->setUpChartOfAccounts();
        $default = $this->tenant->default_currency;

        // No month or currency given: the month of the date and the school's currency.
        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 10, 'expense_date' => '2026-10-30'])
            ->assertCreated()->assertJsonPath('data.for_month', '2026-10')->assertJsonPath('data.currency', $default);

        $id = $this->postJson('/api/v1/expenses', [
            'account_id' => $this->electricityAccount->id, 'amount' => 120000, 'currency' => 'KHR',
            'expense_date' => '2026-10-30', 'for_month' => '2026-09',
        ])->assertCreated()->assertJsonPath('data.for_month', '2026-09')->assertJsonPath('data.currency', 'KHR')->json('data.id');

        $this->postJson('/api/v1/expenses', ['account_id' => $this->electricityAccount->id, 'amount' => 1, 'for_month' => 'Sept', 'currency' => 'EUR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['for_month', 'currency']);

        // Filter by For month.
        $this->getJson('/api/v1/expenses?for_month=2026-09')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/expenses?filter[for_month]=2026-10')->assertOk()->assertJsonCount(1, 'data');

        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles($admin->roles()->first());
        $this->actingAsTenantUser($approver);
        $approver->setRelation('tenant', $this->tenant);
        $this->postJson("/api/v1/expenses/{$id}/approve", [])->assertOk();
        $this->postJson("/api/v1/expenses/{$id}/pay", ['cash_account_id' => $this->cashAccount->id])->assertOk();

        $tx = FinancialTransaction::query()->where('type', TransactionType::EXPENSE)->firstOrFail();
        $this->assertSame('2026-09-01', $tx->for_month->toDateString());
        $this->assertSame('KHR', $tx->currency);
    }

    public function test_manual_income_takes_a_for_month_and_currency(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INCOME_VIEW, Permissions::INCOME_CREATE]);
        $this->setUpChartOfAccounts();
        $default = $this->tenant->default_currency;

        $this->postJson('/api/v1/income', [
            'revenue_account_id' => $this->otherIncomeAccount->id, 'cash_account_id' => $this->cashAccount->id,
            'amount' => 50, 'date' => '2026-10-30', 'for_month' => '2026-09', 'currency' => 'KHR',
        ])->assertCreated()->assertJsonPath('data.for_month', '2026-09')->assertJsonPath('data.currency', 'KHR');

        $this->postJson('/api/v1/income', [
            'revenue_account_id' => $this->otherIncomeAccount->id, 'cash_account_id' => $this->cashAccount->id,
            'amount' => 20, 'date' => '2026-10-30',
        ])->assertCreated()->assertJsonPath('data.for_month', '2026-10')->assertJsonPath('data.currency', $default);

        $this->getJson('/api/v1/income?for_month=2026-09')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 50);
        $this->getJson('/api/v1/income?for_month=bad')->assertUnprocessable();
    }
}
