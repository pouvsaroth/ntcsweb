<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\Account;
use App\Services\Accounting\AccountingReportService;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

class TransferTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    public function test_a_transfer_moves_balance_between_two_cash_accounts(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TRANSACTIONS_CREATE]);
        $this->setUpChartOfAccounts();
        $bank = Account::factory()->forTenant($this->tenant)->bankOrCash()->create(['code' => '1200', 'name' => 'Bank']);

        // Seed the cash account with $500 via a manual adjustment isn't
        // needed — netDebit() works on any starting balance, including
        // negative, so we just verify the *delta* the transfer produces.
        $response = $this->postJson('/api/v1/financial-transactions/transfer', [
            'from_account_id' => $this->cashAccount->id,
            'to_account_id' => $bank->id,
            'amount' => 500,
        ]);

        $response->assertCreated();

        $reports = app(AccountingReportService::class);
        $this->assertSame(-500.0, $reports->netDebit([$this->cashAccount->id]));
        $this->assertSame(500.0, $reports->netDebit([$bank->id]));
    }

    public function test_a_transfer_is_never_counted_as_revenue_or_expense(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TRANSACTIONS_CREATE]);
        $this->setUpChartOfAccounts();
        $bank = Account::factory()->forTenant($this->tenant)->bankOrCash()->create(['code' => '1200', 'name' => 'Bank']);

        $this->postJson('/api/v1/financial-transactions/transfer', [
            'from_account_id' => $this->cashAccount->id,
            'to_account_id' => $bank->id,
            'amount' => 500,
        ])->assertCreated();

        $reports = app(AccountingReportService::class);
        $this->assertSame(0.0, $reports->totalRevenue());
        $this->assertSame(0.0, $reports->totalExpenses());

        // Combined cash+bank total is unaffected — it's an internal move.
        $this->assertSame(0.0, $reports->netDebit([$this->cashAccount->id, $bank->id]));
    }

    public function test_a_transfer_cannot_involve_a_non_cash_account(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TRANSACTIONS_CREATE]);
        $this->setUpChartOfAccounts();

        $response = $this->postJson('/api/v1/financial-transactions/transfer', [
            'from_account_id' => $this->cashAccount->id,
            'to_account_id' => $this->courseFeesAccount->id,
            'amount' => 100,
        ]);

        $response->assertUnprocessable();
    }

    public function test_creating_a_transfer_requires_the_transactions_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpChartOfAccounts();
        $bank = Account::factory()->forTenant($this->tenant)->bankOrCash()->create(['code' => '1200']);

        $this->postJson('/api/v1/financial-transactions/transfer', [
            'from_account_id' => $this->cashAccount->id,
            'to_account_id' => $bank->id,
            'amount' => 100,
        ])->assertForbidden();
    }
}
