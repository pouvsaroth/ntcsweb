<?php

declare(strict_types=1);

namespace Tests\Feature\PoliticalParty;

use App\Models\Commune;
use App\Models\District;
use App\Models\Province;
use App\Models\Village;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Political Party > Locations: provinces, districts, communes and villages, narrowed and searched. */
class PartyLocationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Province $battambang;

    private District $sangke;

    private Commune $anlong;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions([Permissions::PARTY_LOCATIONS_VIEW]);

        $this->battambang = Province::factory()->create(['code' => '02', 'name_km' => 'បាត់ដំបង', 'name_latin' => 'Battambang']);
        $kandal = Province::factory()->create(['code' => '08', 'name_km' => 'កណ្ដាល', 'name_latin' => 'Kandal']);
        $this->sangke = District::factory()->create(['province_id' => $this->battambang->id, 'code' => '0201', 'name_latin' => 'Sangkae']);
        District::factory()->create(['province_id' => $kandal->id, 'code' => '0801', 'name_latin' => 'Kandal Stueng']);
        $this->anlong = Commune::factory()->create(['district_id' => $this->sangke->id, 'code' => '020101', 'name_latin' => 'Anlong Vil']);
        Village::factory()->create(['commune_id' => $this->anlong->id, 'code' => '02010101', 'name_km' => 'អន្លង់វិល', 'name_latin' => 'Anlong Vil Kandal']);
        Village::factory()->create(['commune_id' => $this->anlong->id, 'code' => '02010102', 'name_latin' => 'Ou Ta Ki']);
    }

    public function test_with_nothing_picked_it_lists_provinces_then_the_level_under_each_pick(): void
    {
        $this->getJson('/api/v1/party/locations')->assertOk()
            ->assertJsonPath('meta.pagination.total', 2)
            ->assertJsonPath('data.0.name_latin', 'Battambang')
            ->assertJsonPath('data.0.level', 'province')
            ->assertJsonPath('data.0.children', 1);

        $this->getJson("/api/v1/party/locations?province_id={$this->battambang->id}")->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.level', 'district')
            ->assertJsonPath('data.0.province.name_latin', 'Battambang');

        $villages = $this->getJson("/api/v1/party/locations?province_id={$this->battambang->id}&district_id={$this->sangke->id}&commune_id={$this->anlong->id}")->assertOk()->json('data');
        $this->assertSame(['Anlong Vil Kandal', 'Ou Ta Ki'], array_column($villages, 'name_latin'));
        $this->assertSame('Sangkae', $villages[0]['district']['name_latin']);
        $this->assertSame('Anlong Vil', $villages[0]['commune']['name_latin']);
    }

    public function test_search_finds_every_level_by_khmer_latin_or_code_inside_the_picks(): void
    {
        // "Kandal": the province, its district, and a village in Battambang.
        $found = $this->getJson('/api/v1/party/locations?search=kandal')->assertOk()->json('data');
        $this->assertSame(['province', 'district', 'village'], array_column($found, 'level'));

        $this->assertSame(['Anlong Vil Kandal'], array_column($this->getJson("/api/v1/party/locations?search=kandal&province_id={$this->battambang->id}")->json('data'), 'name_latin'));
        $this->assertSame(['Anlong Vil Kandal'], array_column($this->getJson('/api/v1/party/locations?search='.urlencode('អន្លង់'))->json('data'), 'name_latin'));
        $this->assertSame(['Ou Ta Ki'], array_column($this->getJson('/api/v1/party/locations?search=02010102')->json('data'), 'name_latin'));
        // Only one level.
        $this->assertSame(['commune'], array_column($this->getJson('/api/v1/party/locations?search=anlong&level=commune')->json('data'), 'level'));
    }

    public function test_it_needs_the_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/party/locations')->assertForbidden();
    }
}
