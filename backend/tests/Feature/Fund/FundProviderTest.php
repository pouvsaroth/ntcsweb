<?php

declare(strict_types=1);

namespace Tests\Feature\Fund;

use App\Models\FundProvider;
use App\Models\FundTransaction;
use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

/** Fund > Providers: the people who give money, picked on money in, with what each has given. */
class FundProviderTest extends TestCase
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

    private function fund(string $currency = 'USD', array $overrides = []): array
    {
        return $this->postJson('/api/v1/funds', [
            'name' => "Building {$currency}", 'fund_type' => 'BUILDING', 'currency' => $currency,
            'opened_on' => now()->subDays(10)->toDateString(), ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_providers_are_added_and_picked_on_money_in_and_the_list_shows_what_each_gave(): void
    {
        $sok = $this->postJson('/api/v1/fund-providers', ['name' => 'Sok Dara', 'phone' => '012345678'])->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^P\d{5}$/', $sok['code']);

        // Opening money from a provider.
        $usd = $this->fund('USD', ['opening_amount' => 500, 'cash_account_id' => $this->cashAccount->id, 'fund_provider_id' => $sok['id']]);
        $khr = $this->fund('KHR');

        $this->postJson("/api/v1/funds/{$usd['id']}/entries", [
            'type' => 'in', 'amount' => 200, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id,
            'fund_provider_id' => $sok['id'], 'party' => 'typed name is replaced',
        ])->assertCreated()->assertJsonPath('data.party', 'Sok Dara')->assertJsonPath('data.fund_provider_id', $sok['id']);
        $this->postJson("/api/v1/funds/{$khr['id']}/entries", [
            'type' => 'in', 'amount' => 40000, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id, 'fund_provider_id' => $sok['id'],
        ])->assertCreated();

        // Money out cannot name a provider.
        $this->postJson("/api/v1/funds/{$usd['id']}/entries", [
            'type' => 'out', 'amount' => 10, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id, 'fund_provider_id' => $sok['id'],
        ])->assertJsonValidationErrors('fund_provider_id');

        $this->assertSame(3, FundTransaction::query()->where('fund_provider_id', $sok['id'])->count());
        $row = $this->getJson('/api/v1/fund-providers?search=sok')->assertOk()->json('data.0');
        $this->assertEquals(['USD' => 700, 'KHR' => 40000], $row['given']);

        // An inactive provider cannot be picked.
        $this->putJson("/api/v1/fund-providers/{$sok['id']}", ['is_active' => false])->assertOk();
        $this->postJson("/api/v1/funds/{$usd['id']}/entries", [
            'type' => 'in', 'amount' => 5, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id, 'fund_provider_id' => $sok['id'],
        ])->assertJsonValidationErrors('fund_provider_id');
    }

    public function test_a_provider_who_gave_money_cannot_be_deleted_and_each_action_needs_its_permission(): void
    {
        $used = FundProvider::factory()->create();
        $unused = FundProvider::factory()->create();
        $fund = $this->fund();
        $this->postJson("/api/v1/funds/{$fund['id']}/entries", [
            'type' => 'in', 'amount' => 50, 'transacted_on' => now()->toDateString(), 'cash_account_id' => $this->cashAccount->id, 'fund_provider_id' => $used->id,
        ])->assertCreated();

        $this->deleteJson("/api/v1/fund-providers/{$used->id}")->assertJsonValidationErrors('name');
        $this->deleteJson("/api/v1/fund-providers/{$unused->id}")->assertNoContent();

        $this->actingAsAdminWithPermissions([Permissions::FUNDS_VIEW]);
        $this->getJson('/api/v1/fund-providers')->assertOk();
        $this->postJson('/api/v1/fund-providers', ['name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/fund-providers/{$used->id}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/fund-providers/{$used->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::INVOICES_VIEW]);
        $this->getJson('/api/v1/fund-providers')->assertForbidden();
    }
}
