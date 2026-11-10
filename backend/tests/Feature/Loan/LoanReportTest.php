<?php

declare(strict_types=1);

namespace Tests\Feature\Loan;

use App\Models\LoanCustomer;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Loan > Reports: Savings and Owe for a period, per currency, and each customer position. */
class LoanReportTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private LoanCustomer $dara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([
            Permissions::SAVINGS_VIEW, Permissions::SAVINGS_CREATE,
            Permissions::CUSTOMER_LOANS_VIEW, Permissions::CUSTOMER_LOANS_CREATE,
        ]);
        $this->setUpChartOfAccounts();
        $this->dara = LoanCustomer::factory()->create(['name' => 'Sok Dara']);

        // Savings: 500 last year, 200 in and 150 out this period.
        $account = $this->postJson('/api/v1/savings-accounts', [
            'loan_customer_id' => $this->dara->id, 'currency' => 'USD', 'opened_on' => now()->subYear()->toDateString(),
            'first_deposit' => 500, 'cash_account_id' => $this->cashAccount->id,
        ])->assertCreated()->json('data');
        foreach ([['deposit', 200], ['withdrawal', 150]] as [$type, $amount]) {
            $this->postJson("/api/v1/savings-accounts/{$account['id']}/entries", [
                'type' => $type, 'amount' => $amount, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id,
            ])->assertCreated();
        }

        // Owe: 1,200 lent today, 100 + 12 paid today.
        $loan = $this->postJson('/api/v1/customer-loans', [
            'loan_customer_id' => $this->dara->id, 'amount' => 1200, 'currency' => 'USD', 'lent_on' => now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id, 'interest_type' => 'flat', 'interest_rate' => 1, 'term_months' => 12,
        ])->assertCreated()->json('data');
        $this->postJson("/api/v1/customer-loans/{$loan['id']}/payments", [
            'paid_on' => now()->toDateString(), 'principal' => 100, 'interest' => 12, 'cash_account_id' => $this->cashAccount->id,
        ])->assertCreated();
    }

    public function test_savings_owe_and_positions_for_the_period(): void
    {
        $today = now()->toDateString();
        $data = $this->getJson("/api/v1/loan-reports?from={$today}&to={$today}")->assertOk()->json('data');

        $usd = $data['savings']['totals'][0];
        $this->assertSame('USD', $usd['currency']);
        $this->assertEquals(200, $usd['deposits']);
        $this->assertEquals(150, $usd['withdrawals']);
        $this->assertEquals(550, $usd['balance']);
        $this->assertSame(0, $data['savings']['totals'][1]['accounts']);

        $owe = $data['loans']['totals'][0];
        $this->assertEquals(1200, $owe['lent']);
        $this->assertEquals(100, $owe['collected']);
        $this->assertEquals(12, $owe['interest']);
        $this->assertEquals(1100, $owe['owed']);
        $this->assertSame('Sok Dara', $data['loans']['customers'][0]['name']);

        $this->assertSame([['customer_id' => $this->dara->id, 'name' => 'Sok Dara', 'code' => $this->dara->fresh()->code, 'currency' => 'USD', 'savings' => 550, 'owed' => 1100, 'net' => 550]], $data['positions']);
    }

    public function test_a_period_before_any_activity_still_shows_balances_now(): void
    {
        $data = $this->getJson('/api/v1/loan-reports?from=2020-01-01&to=2020-12-31')->assertOk()->json('data');

        $this->assertEquals(0, $data['savings']['totals'][0]['deposits']);
        $this->assertEquals(550, $data['savings']['totals'][0]['balance']);
        $this->assertEquals(0, $data['loans']['totals'][0]['lent']);
        $this->assertEquals(1100, $data['loans']['totals'][0]['owed']);
    }

    public function test_each_part_needs_its_own_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SAVINGS_VIEW]);
        $data = $this->getJson('/api/v1/loan-reports')->assertOk()->json('data');
        $this->assertNotNull($data['savings']);
        $this->assertNull($data['loans']);
        $this->assertNull($data['positions']);

        $this->actingAsAdminWithPermissions([Permissions::BORROWINGS_VIEW]);
        $this->getJson('/api/v1/loan-reports')->assertForbidden();
    }
}
