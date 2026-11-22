<?php

declare(strict_types=1);

namespace Tests\Feature\Fund;

use App\Models\FundProvider;
use App\Models\FundTransaction;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\Staff;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Fund > Money in: "Provided by" lists HRM > Staff as well as Fund > Providers. */
class FundStaffProviderTest extends TestCase
{
    use HasAcademicAdmin, HasChartOfAccounts, RefreshDatabase;

    private const ALL = [Permissions::FUNDS_VIEW, Permissions::FUNDS_CREATE, Permissions::FUNDS_UPDATE, Permissions::FUNDS_DELETE];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->setUpChartOfAccounts();
        $category = LookupCategory::query()->create(['code' => 'FUND_TYPE', 'name' => 'Fund Type']);
        LookupValue::query()->create(['lookup_category_id' => $category->id, 'code' => 'BUILDING', 'sort_order' => 0]);
    }

    private function fund(array $overrides = []): array
    {
        return $this->postJson('/api/v1/funds', [
            'name' => 'Building', 'fund_type' => 'BUILDING', 'currency' => 'USD',
            'opened_on' => now()->subDays(10)->toDateString(), ...$overrides,
        ])->assertCreated()->json('data');
    }

    private function moneyIn(int $fundId, array $data): TestResponse
    {
        return $this->postJson("/api/v1/funds/{$fundId}/entries", [
            'type' => 'in', 'amount' => 100, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id, ...$data,
        ]);
    }

    public function test_staff_are_listed_and_picked_on_add_fund_and_money_in(): void
    {
        $dara = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok', 'status' => Staff::STATUS_ACTIVE]);
        $gone = Staff::factory()->create(['first_name' => 'Gone', 'last_name' => 'Away', 'status' => Staff::STATUS_RESIGNED]);

        $options = $this->getJson('/api/v1/funds/staff-options')->assertOk()->json('data');
        $this->assertContains($dara->id, array_column($options, 'id'));
        $this->assertNotContains($gone->id, array_column($options, 'id'));
        $this->assertSame('Dara Sok', collect($options)->firstWhere('id', $dara->id)['name']);

        // Add fund: the opening money from a staff member.
        $fund = $this->fund(['opening_amount' => 500, 'cash_account_id' => $this->cashAccount->id, 'staff_id' => $dara->id]);
        $opening = FundTransaction::query()->where('fund_id', $fund['id'])->sole();
        $this->assertSame($dara->id, $opening->staff_id);
        $this->assertSame('Dara Sok', $opening->party);

        // Money in: the staff member's name replaces any typed name.
        $this->moneyIn($fund['id'], ['staff_id' => $dara->id, 'party' => 'typed'])
            ->assertCreated()->assertJsonPath('data.staff_id', $dara->id)->assertJsonPath('data.party', 'Dara Sok');

        // Not both a provider and a staff member; not a resigned staff member; not on money out.
        $provider = FundProvider::factory()->create();
        $this->moneyIn($fund['id'], ['staff_id' => $dara->id, 'fund_provider_id' => $provider->id])->assertJsonValidationErrors('staff_id');
        $this->moneyIn($fund['id'], ['staff_id' => $gone->id])->assertJsonValidationErrors('staff_id');
        $this->moneyIn($fund['id'], ['type' => 'out', 'amount' => 10, 'staff_id' => $dara->id])->assertJsonValidationErrors('staff_id');
    }

    public function test_copy_keeps_the_staff_member_and_reports_count_staff(): void
    {
        $dara = Staff::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok', 'status' => Staff::STATUS_ACTIVE, 'employee_code' => 'E0007']);
        $sok = FundProvider::factory()->create(['name' => 'Sok Provider']);
        $fund = $this->fund();

        $this->moneyIn($fund['id'], ['amount' => 300, 'fund_provider_id' => $sok->id])->assertCreated();
        $this->moneyIn($fund['id'], ['amount' => 200, 'staff_id' => $dara->id])->assertCreated();
        $this->moneyIn($fund['id'], ['amount' => 50])->assertCreated();

        $this->postJson("/api/v1/funds/{$fund['id']}/entries/copy", ['entries' => [[
            'amount' => 25, 'transacted_on' => now()->toDateString(), 'for_month' => now()->format('Y-m'),
            'cash_account_id' => $this->cashAccount->id, 'staff_id' => $dara->id,
        ]]])->assertCreated();
        $this->assertSame(2, FundTransaction::query()->where('staff_id', $dara->id)->count());

        $this->postJson("/api/v1/funds/{$fund['id']}/entries/copy", ['entries' => [[
            'amount' => 25, 'transacted_on' => now()->toDateString(), 'for_month' => now()->format('Y-m'),
            'cash_account_id' => $this->cashAccount->id, 'staff_id' => $dara->id, 'fund_provider_id' => $sok->id,
        ]]])->assertJsonValidationErrors('entries.0.staff_id');

        $rows = $this->getJson('/api/v1/fund-reports')->assertOk()->json('data.providers');
        $this->assertSame(
            [['Sok Provider', null, 1, 300], ['Dara Sok', 'E0007', 2, 225], [null, null, 1, 50]],
            array_map(fn ($r) => [$r['name'], $r['staff_id'] !== null ? $r['code'] : null, $r['entries'], (int) $r['money_in']], $rows),
        );
        $this->assertSame($dara->id, $rows[1]['staff_id']);
        $this->assertNull($rows[1]['provider_id']);
    }
}
