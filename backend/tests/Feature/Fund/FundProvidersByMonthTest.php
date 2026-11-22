<?php

declare(strict_types=1);

namespace Tests\Feature\Fund;

use App\Models\FundProvider;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Fund > Providers by month: money in per provider / staff member, one column per month. */
class FundProvidersByMonthTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([Permissions::FUNDS_VIEW, Permissions::FUNDS_CREATE, Permissions::FUNDS_UPDATE]);
        $this->setUpChartOfAccounts();
        $category = LookupCategory::query()->create(['code' => 'FUND_TYPE', 'name' => 'Fund Type']);
        LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => 'BUILDING', 'sort_order' => 0]);
    }

    private function fund(string $currency): int
    {
        return $this->postJson('/api/v1/funds', [
            'name' => "Building {$currency}", 'fund_type' => 'BUILDING', 'currency' => $currency, 'opened_on' => '2026-01-01',
        ])->assertCreated()->json('data.id');
    }

    private function moneyIn(int $fundId, float $amount, string $date, ?string $forMonth, array $who): void
    {
        $this->postJson("/api/v1/funds/{$fundId}/entries", [
            'type' => 'in', 'amount' => $amount, 'transacted_on' => $date, 'for_month' => $forMonth,
            'cash_account_id' => $this->cashAccount->id, ...$who,
        ])->assertCreated();
    }

    public function test_money_in_is_split_by_for_month_with_totals_and_filters(): void
    {
        $this->travelTo('2026-04-20 10:00:00');
        $usd = $this->fund('USD');
        $khr = $this->fund('KHR');
        $sok = FundProvider::factory()->create(['name' => 'Sok Provider', 'code' => 'P001']);
        $dara = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok', 'status' => Staff::STATUS_ACTIVE, 'employee_code' => 'E0007']);

        $this->moneyIn($usd, 100, '2026-02-03', '2026-01', ['fund_provider_id' => $sok->id]); // paid in Feb for January
        $this->moneyIn($usd, 50, '2026-02-10', null, ['fund_provider_id' => $sok->id]);        // no For month: its date
        $this->moneyIn($usd, 30, '2026-03-05', '2026-03', ['staff_id' => $dara->id]);
        $this->moneyIn($khr, 40000, '2026-03-06', '2026-03', ['staff_id' => $dara->id]);
        $this->moneyIn($usd, 999, '2026-03-06', '2025-12', ['fund_provider_id' => $sok->id]); // before the period

        $data = $this->getJson('/api/v1/fund-reports/providers-by-month?from_month=2026-01&to_month=2026-04')->assertOk()->json('data');

        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04'], $data['months']);
        $this->assertSame(
            [['Sok Provider', 'P001', 'USD', [100, 50, 0, 0], 150], ['Dara Sok', 'E0007', 'USD', [0, 0, 30, 0], 30], ['Dara Sok', 'E0007', 'KHR', [0, 0, 40000, 0], 40000]],
            array_map(fn ($r) => [$r['name'], $r['code'], $r['currency'], array_map('intval', array_values($r['months'])), (int) $r['total']], $data['rows']),
        );
        // A Total row per currency, never USD + KHR together.
        $this->assertSame(
            [['USD', [100, 50, 30, 0], 180, 2], ['KHR', [0, 0, 40000, 0], 40000, 1]],
            array_map(fn ($t) => [$t['currency'], array_map('intval', array_values($t['months'])), (int) $t['total'], $t['people']], $data['totals']),
        );

        // One provider, one staff member, all staff.
        $one = $this->getJson("/api/v1/fund-reports/providers-by-month?from_month=2026-01&to_month=2026-04&who=provider:{$sok->id}")->json('data.rows');
        $this->assertSame(['Sok Provider'], array_column($one, 'name'));
        $staff = $this->getJson("/api/v1/fund-reports/providers-by-month?from_month=2026-01&to_month=2026-04&who=staff:{$dara->id}")->json('data.rows');
        $this->assertSame(['USD', 'KHR'], array_column($staff, 'currency'));
        $allStaff = $this->getJson('/api/v1/fund-reports/providers-by-month?from_month=2026-01&to_month=2026-04&who=staff')->json('data.rows');
        $this->assertSame(['Dara Sok', 'Dara Sok'], array_column($allStaff, 'name'));

        $this->getJson('/api/v1/fund-reports/providers-by-month?who=nobody')->assertJsonValidationErrors('who');

        $this->get('/api/v1/fund-reports/providers-by-month/excel?from_month=2026-01&to_month=2026-04')
            ->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_it_needs_funds_view(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/fund-reports/providers-by-month')->assertForbidden();
    }
}
