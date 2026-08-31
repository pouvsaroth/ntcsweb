<?php

declare(strict_types=1);

namespace Tests\Feature\Geography;

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Reference data endpoints for the student registration form's cascading
 * address selects. Uses factory-built rows, not the real ~16,000-row
 * Cambodia dataset — these tests are about the filtering logic (does
 * `districts?province_id=X` actually filter by that province), not about
 * the data's real-world content.
 */
class GeographyControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_lists_all_provinces(): void
    {
        $this->actingAsAdminWithPermissions([]);
        Province::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/geo/provinces');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_it_lists_districts_for_a_given_province_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $province = Province::factory()->create();
        District::factory()->count(2)->create(['province_id' => $province->id]);
        District::factory()->create(); // a different province

        $response = $this->getJson("/api/v1/geo/districts?province_id={$province->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_lists_communes_for_a_given_district_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $district = District::factory()->create();
        Commune::factory()->count(2)->create(['district_id' => $district->id]);
        Commune::factory()->create();

        $response = $this->getJson("/api/v1/geo/communes?district_id={$district->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_lists_villages_for_a_given_commune_only(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $commune = Commune::factory()->create();
        Village::factory()->count(2)->create(['commune_id' => $commune->id]);
        Village::factory()->create();

        $response = $this->getJson("/api/v1/geo/villages?commune_id={$commune->id}");

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_a_nonexistent_province_id_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $response = $this->getJson('/api/v1/geo/districts?province_id=999999');

        $response->assertUnprocessable();
    }

    public function test_lookup_resolves_a_villages_full_ancestry_from_its_code(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $province = Province::factory()->create();
        $district = District::factory()->create(['province_id' => $province->id]);
        $commune = Commune::factory()->create(['district_id' => $district->id]);
        $village = Village::factory()->create(['commune_id' => $commune->id]);

        $response = $this->getJson("/api/v1/geo/lookup?village_code={$village->code}");

        $response->assertOk();
        $response->assertJsonPath('data.province.id', $province->id);
        $response->assertJsonPath('data.district.id', $district->id);
        $response->assertJsonPath('data.commune.id', $commune->id);
        $response->assertJsonPath('data.village.id', $village->id);
    }

    public function test_lookup_404s_for_an_unknown_village_code(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $response = $this->getJson('/api/v1/geo/lookup?village_code=doesnotexist');

        $response->assertUnprocessable();
    }
}
