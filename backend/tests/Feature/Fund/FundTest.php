<?php

declare(strict_types=1);

namespace Tests\Feature\Fund;

use App\Models\FinancialTransaction;
use App\Models\Fund;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Support\Accounting\TransactionType;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Fund > Funds: pots of money with a type, money in / out with remarks, and the ledger kept in step. */
class FundTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [
        Permissions::FUNDS_VIEW, Permissions::FUNDS_CREATE, Permissions::FUNDS_UPDATE, Permissions::FUNDS_DELETE,
        Permissions::REPORTS_FINANCIAL_VIEW,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();

        $category = LookupCategory::query()->create(['code' => 'FUND_TYPE', 'name' => 'Fund Type']);
        foreach (['BUILDING', 'SCHOLARSHIP'] as $i => $code) {
            LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => $code, 'sort_order' => $i]);
        }
    }

    private function create(array $overrides = []): array
    {
        return $this->postJson('/api/v1/funds', [
            'name' => 'Building fund',
            'fund_type' => 'BUILDING',
            'currency' => 'USD',
            'opened_on' => now()->subDays(10)->toDateString(),
            'opening_amount' => 1000,
            'cash_account_id' => $this->cashAccount->id,
            'party' => 'Board',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function entry(int $fundId, string $type, float $amount, ?string $date = null, ?string $party = null, ?string $remark = null)
    {
        return $this->postJson("/api/v1/funds/{$fundId}/entries", [
            'type' => $type, 'amount' => $amount, 'transacted_on' => $date ?? now()->toDateString(),
            'cash_account_id' => $this->cashAccount->id, 'party' => $party, 'remark' => $remark,
        ]);
    }

    private function cash(): float
    {
        $id = $this->cashAccount->id;

        return (float) FinancialTransaction::query()->where('debit_account_id', $id)->sum('amount') - (float) FinancialTransaction::query()->where('credit_account_id', $id)->sum('amount');
    }

    public function test_a_fund_with_opening_money_posts_cash_and_the_funds_account(): void
    {
        $fund = $this->create();

        $this->assertMatchesRegularExpression('/^F\d{5}$/', $fund['fund_no']);
        $this->assertSame('BUILDING', $fund['fund_type']);
        $this->assertEquals(1000, $fund['balance']);
        $this->assertSame('Board', $fund['statement'][0]['party']);

        $posted = FinancialTransaction::query()->where('type', TransactionType::FUND)->sole();
        $this->assertSame($this->cashAccount->id, $posted->debit_account_id);
        $this->assertSame('Funds', $posted->creditAccount->name);
        $this->assertSame('EQUITY', $posted->creditAccount->type);
        $this->assertEquals(1000, $this->cash());
    }

    public function test_the_type_must_come_from_the_list(): void
    {
        $this->postJson('/api/v1/funds', ['name' => 'X', 'fund_type' => 'NOPE', 'currency' => 'USD', 'opened_on' => now()->toDateString()])
            ->assertJsonValidationErrors('fund_type');
    }

    public function test_money_in_and_out_keep_a_running_balance_and_never_go_below_zero(): void
    {
        $fund = $this->create();
        $this->entry($fund['id'], 'in', 500, party: 'Parents', remark: 'Donation')->assertCreated();
        $this->entry($fund['id'], 'out', 1200, party: 'ABC Construction', remark: 'Roof')->assertCreated()->assertJsonPath('data.party', 'ABC Construction');
        $this->entry($fund['id'], 'out', 400)->assertJsonValidationErrors('amount');

        $shown = $this->getJson("/api/v1/funds/{$fund['id']}")->assertOk()->json('data');
        $this->assertEquals(300, $shown['balance']);
        $this->assertEquals([1000, 1500, 300], array_column($shown['statement'], 'balance'));
        $this->assertEquals(300, $this->cash());
    }

    public function test_removing_entries_reverses_the_ledger_but_not_money_later_spending_needs(): void
    {
        $fund = $this->create(['opening_amount' => 100]);
        $in = $this->entry($fund['id'], 'in', 50)->assertCreated()->json('data');
        $out = $this->entry($fund['id'], 'out', 120)->assertCreated()->json('data');

        $this->deleteJson("/api/v1/fund-entries/{$in['id']}")->assertJsonValidationErrors('entry');
        $this->deleteJson("/api/v1/fund-entries/{$out['id']}")->assertOk()->assertJsonPath('data.balance', 150);
        $this->deleteJson("/api/v1/fund-entries/{$in['id']}")->assertOk()->assertJsonPath('data.balance', 100);
        $this->assertEquals(100, $this->cash());
    }

    public function test_closing_needs_a_zero_balance_and_a_fund_with_entries_cannot_be_deleted(): void
    {
        $fund = $this->create();
        $this->putJson("/api/v1/funds/{$fund['id']}", ['status' => 'closed'])->assertJsonValidationErrors('status');
        $this->deleteJson("/api/v1/funds/{$fund['id']}")->assertJsonValidationErrors('status');

        $this->entry($fund['id'], 'out', 1000)->assertCreated();
        $this->putJson("/api/v1/funds/{$fund['id']}", ['status' => 'closed', 'name' => 'Old building fund', 'fund_type' => 'SCHOLARSHIP'])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.fund_type', 'SCHOLARSHIP');
        $this->entry($fund['id'], 'in', 10)->assertJsonValidationErrors('status');

        $empty = $this->create(['currency' => 'KHR', 'opening_amount' => null, 'cash_account_id' => null]);
        $this->deleteJson("/api/v1/funds/{$empty['id']}")->assertNoContent();
    }

    public function test_list_filters_summary_and_cash_flow(): void
    {
        $this->create(['opened_on' => now()->toDateString()]);
        $other = $this->create(['name' => 'Scholarship fund', 'fund_type' => 'SCHOLARSHIP', 'currency' => 'KHR', 'opening_amount' => 40000, 'opened_on' => now()->toDateString()]);
        $this->entry($other['id'], 'out', 10000)->assertCreated();

        $this->getJson('/api/v1/funds?search=scholar')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/funds?filter[fund_type]=BUILDING')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Building fund');
        $this->getJson('/api/v1/funds/summary')->assertOk()
            ->assertJsonPath('data.0.currency', 'USD')->assertJsonPath('data.0.balance', 1000)
            ->assertJsonPath('data.1.currency', 'KHR')->assertJsonPath('data.1.balance', 30000);

        $today = now()->toDateString();
        $flow = $this->getJson("/api/v1/accounting/reports/cash-flow?date_from={$today}&date_to={$today}")->assertOk()->json('data');
        $this->assertEquals(41000, $flow['fund_in']);
        $this->assertEquals(10000, $flow['fund_out']);
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $fund = Fund::factory()->create();
        $this->actingAsAdminWithPermissions([Permissions::FUNDS_VIEW]);

        $this->getJson('/api/v1/funds')->assertOk();
        $this->postJson('/api/v1/funds', ['name' => 'X'])->assertForbidden();
        $this->entry($fund->id, 'in', 10)->assertForbidden();
        $this->putJson("/api/v1/funds/{$fund->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/funds/{$fund->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/funds')->assertForbidden();
    }
}
