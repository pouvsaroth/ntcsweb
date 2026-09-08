<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\CurrencyRate;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class CurrencyRateControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_lists_currency_rates(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRENCY_RATES_VIEW]);

        CurrencyRate::factory()->count(2)->create();

        $response = $this->getJson('/api/v1/currency-rates');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_creates_a_currency_rate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRENCY_RATES_CREATE]);

        $response = $this->postJson('/api/v1/currency-rates', [
            'effective_date' => '2026-01-01',
            'khr_per_usd' => 4100.5,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.effective_date', '2026-01-01');
        $response->assertJsonPath('data.khr_per_usd', 4100.5);
        $this->assertDatabaseHas('currency_rates', ['effective_date' => '2026-01-01'], 'tenant');
    }

    public function test_effective_date_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRENCY_RATES_CREATE]);
        CurrencyRate::factory()->create(['effective_date' => '2026-01-01']);

        $response = $this->postJson('/api/v1/currency-rates', ['effective_date' => '2026-01-01', 'khr_per_usd' => 4000]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('effective_date');
    }

    public function test_it_updates_a_currency_rate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRENCY_RATES_UPDATE]);
        $rate = CurrencyRate::factory()->create(['khr_per_usd' => 4000]);

        $response = $this->putJson("/api/v1/currency-rates/{$rate->id}", ['khr_per_usd' => 4150]);

        $response->assertOk();
        $response->assertJsonPath('data.khr_per_usd', 4150);
    }

    public function test_it_deletes_a_currency_rate(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CURRENCY_RATES_DELETE]);
        $rate = CurrencyRate::factory()->create();

        $this->deleteJson("/api/v1/currency-rates/{$rate->id}")->assertNoContent();
        $this->assertSoftDeleted('currency_rates', ['id' => $rate->id], connection: 'tenant');
    }
}
