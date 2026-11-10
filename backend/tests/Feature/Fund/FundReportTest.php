<?php

declare(strict_types=1);

namespace Tests\Feature\Fund;

use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Fund > Reports: balance at start, in, out and balance at end, per currency, by type and by fund. */
class FundReportTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([Permissions::FUNDS_VIEW, Permissions::FUNDS_CREATE]);
        $this->setUpChartOfAccounts();

        $category = LookupCategory::query()->create(['code' => 'FUND_TYPE', 'name' => 'Fund Type']);
        foreach (['BUILDING', 'SCHOLARSHIP'] as $i => $code) {
            LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => $code, 'sort_order' => $i]);
        }

        // Building: 1,000 a month ago; this week +300 in, -200 out.
        $building = $this->fund('Building fund', 'BUILDING', 'USD', 1000, now()->subMonth()->toDateString());
        $this->entry($building, 'in', 300, now()->subDays(2)->toDateString());
        $this->entry($building, 'out', 200, now()->subDay()->toDateString());
        // Scholarship: 500 in this week; KHR building fund 400,000 this week.
        $this->fund('Scholarship fund', 'SCHOLARSHIP', 'USD', 500, now()->subDays(3)->toDateString());
        $this->fund('Building KHR', 'BUILDING', 'KHR', 400000, now()->subDays(3)->toDateString());
        // An empty fund is left out of the report.
        $this->fund('Empty fund', 'SCHOLARSHIP', 'USD', null, now()->subDays(3)->toDateString());
    }

    private function fund(string $name, string $type, string $currency, ?float $opening, string $openedOn): int
    {
        return $this->postJson('/api/v1/funds', [
            'name' => $name, 'fund_type' => $type, 'currency' => $currency, 'opened_on' => $openedOn,
            ...($opening ? ['opening_amount' => $opening, 'cash_account_id' => $this->cashAccount->id] : []),
        ])->assertCreated()->json('data.id');
    }

    private function entry(int $fundId, string $type, float $amount, string $date): void
    {
        $this->postJson("/api/v1/funds/{$fundId}/entries", [
            'type' => $type, 'amount' => $amount, 'transacted_on' => $date, 'cash_account_id' => $this->cashAccount->id,
        ])->assertCreated();
    }

    public function test_totals_types_and_funds_for_the_period(): void
    {
        $from = now()->subWeek()->toDateString();
        $to = now()->toDateString();
        $data = $this->getJson("/api/v1/fund-reports?from={$from}&to={$to}")->assertOk()->json('data');

        [$usd, $khr] = $data['totals'];
        $this->assertSame('USD', $usd['currency']);
        $this->assertSame(2, $usd['funds']);
        $this->assertEquals(1000, $usd['opening']);
        $this->assertEquals(800, $usd['money_in']);
        $this->assertEquals(200, $usd['money_out']);
        $this->assertEquals(1600, $usd['closing']);
        $this->assertEquals(400000, $khr['money_in']);
        $this->assertEquals(400000, $khr['closing']);

        $building = collect($data['types'])->firstWhere(fn ($r) => $r['fund_type'] === 'BUILDING' && $r['currency'] === 'USD');
        $this->assertEquals(1100, $building['closing']);
        $this->assertCount(3, $data['types']);

        // The empty fund is left out.
        $this->assertEqualsCanonicalizing(['Building fund', 'Scholarship fund', 'Building KHR'], collect($data['funds'])->pluck('name')->all());
        $fund = collect($data['funds'])->firstWhere('name', 'Building fund');
        $this->assertEquals([1000, 300, 200, 1100], [$fund['opening'], $fund['money_in'], $fund['money_out'], $fund['closing']]);
    }

    public function test_the_end_date_and_type_filter(): void
    {
        // Up to 3 days ago: only the building opening money is in.
        $to = now()->subDays(3)->toDateString();
        $data = $this->getJson('/api/v1/fund-reports?from='.now()->subMonths(2)->toDateString()."&to={$to}&fund_type=BUILDING")->assertOk()->json('data');

        $this->assertEquals(1000, $data['totals'][0]['closing']);
        $this->assertEquals(0, $data['totals'][0]['money_out']);
        $this->assertSame(['BUILDING'], collect($data['types'])->pluck('fund_type')->unique()->values()->all());
        $this->assertNotContains('Scholarship fund', collect($data['funds'])->pluck('name'));
    }

    public function test_it_needs_funds_view(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::SAVINGS_VIEW]);
        $this->getJson('/api/v1/fund-reports')->assertForbidden();
    }
}
