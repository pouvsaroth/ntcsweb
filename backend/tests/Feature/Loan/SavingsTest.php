<?php

declare(strict_types=1);

namespace Tests\Feature\Loan;

use App\Models\FinancialTransaction;
use App\Models\LoanCustomer;
use App\Models\SavingsAccount;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Loan > Savings: accounts, deposits / withdrawals with remarks, and the ledger kept in step. */
class SavingsTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [
        Permissions::SAVINGS_VIEW, Permissions::SAVINGS_CREATE, Permissions::SAVINGS_UPDATE, Permissions::SAVINGS_DELETE,
        Permissions::LOAN_CUSTOMERS_VIEW, Permissions::LOAN_CUSTOMERS_DELETE, Permissions::REPORTS_FINANCIAL_VIEW,
    ];

    private LoanCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();
        $this->customer = LoanCustomer::factory()->create(['name' => 'Sok Dara']);
    }

    private function open(array $overrides = []): array
    {
        return $this->postJson('/api/v1/savings-accounts', [
            'loan_customer_id' => $this->customer->id,
            'currency' => 'USD',
            'opened_on' => now()->subDays(10)->toDateString(),
            'first_deposit' => 500,
            'cash_account_id' => $this->cashAccount->id,
            'remark' => 'First saving',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function entry(int $accountId, string $type, float $amount, ?string $date = null, ?string $remark = null)
    {
        return $this->postJson("/api/v1/savings-accounts/{$accountId}/entries", [
            'type' => $type, 'amount' => $amount, 'transacted_on' => $date ?? now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id, 'remark' => $remark,
        ]);
    }

    private function cash(): float
    {
        $id = $this->cashAccount->id;

        return (float) FinancialTransaction::query()->where('debit_account_id', $id)->sum('amount') - (float) FinancialTransaction::query()->where('credit_account_id', $id)->sum('amount');
    }

    public function test_opening_with_a_first_deposit_posts_cash_and_customer_savings(): void
    {
        $account = $this->open();

        $this->assertMatchesRegularExpression('/^S\d{5}$/', $account['account_no']);
        $this->assertEquals(500, $account['balance']);
        $this->assertSame('First saving', $account['statement'][0]['remark']);
        $this->assertEquals(500, $account['statement'][0]['balance']);

        $posted = FinancialTransaction::query()->where('type', TransactionType::SAVINGS)->sole();
        $this->assertSame($this->cashAccount->id, $posted->debit_account_id);
        $this->assertSame('Customer Savings', $posted->creditAccount->name);
        $this->assertEquals(500, $this->cash());
    }

    public function test_deposits_and_withdrawals_keep_a_running_balance_with_remarks(): void
    {
        $account = $this->open();
        $this->entry($account['id'], 'deposit', 200, remark: 'Salary')->assertCreated();
        $this->entry($account['id'], 'withdrawal', 150, remark: 'School fee')->assertCreated()->assertJsonPath('data.remark', 'School fee');

        $shown = $this->getJson("/api/v1/savings-accounts/{$account['id']}")->assertOk()->json('data');
        $this->assertEquals(550, $shown['balance']);
        $this->assertEquals([500, 700, 550], array_column($shown['statement'], 'balance'));
        $this->assertEquals(550, $this->cash());
    }

    public function test_a_withdrawal_cannot_overdraw_on_any_day(): void
    {
        $account = $this->open(['first_deposit' => 100]);

        $this->entry($account['id'], 'withdrawal', 150)->assertJsonValidationErrors('amount');

        // Backdated before a later deposit: still short on that day.
        $this->entry($account['id'], 'deposit', 300)->assertCreated();
        $this->entry($account['id'], 'withdrawal', 150, now()->subDays(5)->toDateString())->assertJsonValidationErrors('amount');
        $this->entry($account['id'], 'withdrawal', 400)->assertCreated();
        $this->assertEquals(0, $this->cash());
    }

    public function test_removing_entries_reverses_the_ledger_but_not_a_deposit_later_withdrawals_need(): void
    {
        $account = $this->open(['first_deposit' => 100]);
        $deposit = $this->entry($account['id'], 'deposit', 50)->assertCreated()->json('data');
        $withdrawal = $this->entry($account['id'], 'withdrawal', 120)->assertCreated()->json('data');

        $this->deleteJson("/api/v1/savings-entries/{$deposit['id']}")->assertJsonValidationErrors('entry');

        $this->deleteJson("/api/v1/savings-entries/{$withdrawal['id']}")->assertOk()->assertJsonPath('data.balance', 150);
        $this->deleteJson("/api/v1/savings-entries/{$deposit['id']}")->assertOk()->assertJsonPath('data.balance', 100);
        $this->assertEquals(100, $this->cash());
    }

    public function test_one_account_per_currency_and_closing_needs_a_zero_balance(): void
    {
        $account = $this->open();
        $this->postJson('/api/v1/savings-accounts', ['loan_customer_id' => $this->customer->id, 'currency' => 'USD', 'opened_on' => now()->toDateString()])
            ->assertJsonValidationErrors('currency');
        $this->postJson('/api/v1/savings-accounts', ['loan_customer_id' => $this->customer->id, 'currency' => 'KHR', 'opened_on' => now()->toDateString()])
            ->assertCreated()->assertJsonPath('data.balance', 0);

        $this->putJson("/api/v1/savings-accounts/{$account['id']}", ['status' => 'closed'])->assertJsonValidationErrors('status');
        $this->entry($account['id'], 'withdrawal', 500)->assertCreated();
        $this->putJson("/api/v1/savings-accounts/{$account['id']}", ['status' => 'closed'])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->entry($account['id'], 'deposit', 10)->assertJsonValidationErrors('status');

        $this->getJson('/api/v1/savings-accounts/summary')->assertOk()
            ->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('data.0.accounts', 0)
            ->assertJsonPath('data.1.currency', 'KHR')->assertJsonPath('data.1.accounts', 1);
    }

    public function test_an_account_with_entries_and_a_customer_with_savings_cannot_be_deleted(): void
    {
        $account = $this->open();

        $this->deleteJson("/api/v1/savings-accounts/{$account['id']}")->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/loan-customers/{$this->customer->id}")->assertJsonValidationErrors('name');

        $empty = $this->open(['currency' => 'KHR', 'first_deposit' => null, 'cash_account_id' => null]);
        $this->deleteJson("/api/v1/savings-accounts/{$empty['id']}")->assertNoContent();
    }

    public function test_cash_flow_shows_savings_in_and_out_and_still_adds_up(): void
    {
        $account = $this->open(['opened_on' => now()->toDateString()]);
        $this->entry($account['id'], 'withdrawal', 120)->assertCreated();

        $today = now()->toDateString();
        $flow = $this->getJson("/api/v1/accounting/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');

        $this->assertEquals(500, $flow['savings_deposits']);
        $this->assertEquals(120, $flow['savings_withdrawals']);
        $this->assertEquals(380, $flow['closing']);
    }

    public function test_search_finds_by_customer_name(): void
    {
        $this->open();

        $this->getJson('/api/v1/savings-accounts?search=dara')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.balance', 500);
        $this->getJson('/api/v1/savings-accounts?search=nobody')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $account = SavingsAccount::factory()->create(['loan_customer_id' => $this->customer->id]);
        $this->actingAsAdminWithPermissions([Permissions::SAVINGS_VIEW]);

        $this->getJson('/api/v1/savings-accounts')->assertOk();
        $this->postJson('/api/v1/savings-accounts', ['loan_customer_id' => $this->customer->id, 'currency' => 'KHR', 'opened_on' => now()->toDateString()])->assertForbidden();
        $this->entry($account->id, 'deposit', 10)->assertForbidden();
        $this->putJson("/api/v1/savings-accounts/{$account->id}", ['notes' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/savings-accounts/{$account->id}")->assertForbidden();
    }
}
