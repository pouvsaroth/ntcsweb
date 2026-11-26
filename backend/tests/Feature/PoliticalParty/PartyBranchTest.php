<?php

declare(strict_types=1);

namespace Tests\Feature\PoliticalParty;

use App\Models\Commune;
use App\Models\District;
use App\Models\PartyBranch;
use App\Models\Province;
use App\Models\Village;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Political Party > Branches: branches / offices placed at a province, district, commune or village. */
class PartyBranchTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PARTY_BRANCHES_VIEW, Permissions::PARTY_BRANCHES_CREATE, Permissions::PARTY_BRANCHES_UPDATE, Permissions::PARTY_BRANCHES_DELETE];

    private Province $battambang;

    private District $sangke;

    private Commune $anlong;

    private Village $village;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->battambang = Province::factory()->create(['name_km' => 'បាត់ដំបង', 'name_latin' => 'Battambang']);
        $this->sangke = District::factory()->create(['province_id' => $this->battambang->id, 'name_latin' => 'Sangkae']);
        $this->anlong = Commune::factory()->create(['district_id' => $this->sangke->id, 'name_latin' => 'Anlong Vil']);
        $this->village = Village::factory()->create(['commune_id' => $this->anlong->id, 'name_latin' => 'Ou Ta Ki']);
    }

    private function branch(array $overrides = [])
    {
        return $this->postJson('/api/v1/party-branches', [
            'name' => 'Ou Ta Ki branch',
            'level' => 'village',
            'location_id' => $this->village->id,
            'leader_name' => 'Sok Dara',
            'leader_phone' => '012 345 678',
            'opened_on' => '2026-01-15',
            ...$overrides,
        ]);
    }

    public function test_a_branch_gets_a_code_and_every_level_above_its_place(): void
    {
        $branch = $this->branch()->assertCreated()->json('data');

        $this->assertMatchesRegularExpression('/^BR\d{5}$/', $branch['code']);
        $this->assertSame('Battambang', $branch['province']['name_latin']);
        $this->assertSame('Sangkae', $branch['district']['name_latin']);
        $this->assertSame('Anlong Vil', $branch['commune']['name_latin']);
        $this->assertSame('Ou Ta Ki', $branch['village']['name_latin']);
        $this->assertSame('2026-01-15', $branch['opened_on']);
        $this->assertTrue($branch['is_active']);

        // Moved up to the district: the commune and village are cleared.
        $moved = $this->putJson("/api/v1/party-branches/{$branch['id']}", ['name' => 'Sangkae office', 'level' => 'district', 'location_id' => $this->sangke->id, 'is_active' => false])
            ->assertOk()->json('data');
        $this->assertSame('Sangkae', $moved['district']['name_latin']);
        $this->assertNull($moved['commune']);
        $this->assertNull($moved['village']);
        $this->assertFalse($moved['is_active']);
        $this->assertSame($this->battambang->id, PartyBranch::query()->findOrFail($branch['id'])->province_id);
    }

    public function test_the_place_must_exist_at_the_chosen_level(): void
    {
        $this->branch(['level' => 'commune', 'location_id' => 999999])->assertJsonValidationErrors('location_id');
        $this->branch(['level' => 'town'])->assertJsonValidationErrors('level');
        $this->branch(['name' => ''])->assertJsonValidationErrors('name');
    }

    public function test_the_list_narrows_by_place_level_status_and_search_and_exports_to_excel(): void
    {
        $this->branch()->assertCreated();
        $this->branch(['name' => 'Battambang provincial office', 'level' => 'province', 'location_id' => $this->battambang->id, 'leader_name' => 'Chan Thy'])->assertCreated();
        $other = Province::factory()->create(['name_latin' => 'Kandal']);
        $this->branch(['name' => 'Kandal office', 'level' => 'province', 'location_id' => $other->id, 'is_active' => false])->assertCreated();

        $this->getJson('/api/v1/party-branches')->assertOk()->assertJsonPath('meta.pagination.total', 3);
        $this->getJson("/api/v1/party-branches?filter[province_id]={$this->battambang->id}")->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->getJson("/api/v1/party-branches?filter[commune_id]={$this->anlong->id}")->assertOk()->assertJsonPath('meta.pagination.total', 1);
        $this->getJson('/api/v1/party-branches?filter[level]=province')->assertOk()->assertJsonPath('meta.pagination.total', 2);
        $this->getJson('/api/v1/party-branches?filter[is_active]=0')->assertOk()->assertJsonPath('data.0.name', 'Kandal office');
        $this->getJson('/api/v1/party-branches?search=chan')->assertOk()->assertJsonPath('data.0.leader_name', 'Chan Thy');

        $response = $this->get("/api/v1/party-branches/excel?filter[province_id]={$this->battambang->id}")->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', (string) $response->getContent());
    }

    public function test_each_action_needs_its_own_permission(): void
    {
        $id = $this->branch()->assertCreated()->json('data.id');

        $this->actingAsAdminWithPermissions([Permissions::PARTY_BRANCHES_VIEW]);
        $this->getJson('/api/v1/party-branches')->assertOk();
        $this->branch()->assertForbidden();
        $this->putJson("/api/v1/party-branches/{$id}", ['name' => 'X', 'level' => 'province', 'location_id' => $this->battambang->id])->assertForbidden();
        $this->deleteJson("/api/v1/party-branches/{$id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PARTY_BRANCHES_DELETE, Permissions::PARTY_BRANCHES_VIEW]);
        $this->deleteJson("/api/v1/party-branches/{$id}")->assertNoContent();

        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/party-branches')->assertForbidden();
        $this->get('/api/v1/party-branches/excel')->assertForbidden();
    }
}
