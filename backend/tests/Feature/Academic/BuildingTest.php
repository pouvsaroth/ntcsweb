<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Building;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class BuildingTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_and_lists_buildings(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BUILDINGS_VIEW, Permissions::BUILDINGS_CREATE]);

        $this->postJson('/api/v1/buildings', ['name' => 'Main Building'])->assertCreated();

        $response = $this->getJson('/api/v1/buildings');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_building_name_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BUILDINGS_CREATE]);
        Building::factory()->create(['name' => 'Main Building']);

        $response = $this->postJson('/api/v1/buildings', ['name' => 'Main Building']);

        $response->assertUnprocessable();
    }

    public function test_it_updates_and_deletes_a_building(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::BUILDINGS_UPDATE, Permissions::BUILDINGS_DELETE]);
        $building = Building::factory()->create();

        $this->putJson("/api/v1/buildings/{$building->id}", ['address' => '123 School Road'])
            ->assertOk()
            ->assertJsonPath('data.address', '123 School Road');

        $this->deleteJson("/api/v1/buildings/{$building->id}")->assertNoContent();
        $this->assertSoftDeleted('buildings', ['id' => $building->id]);
    }

    public function test_a_classroom_can_be_linked_to_a_building(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE, Permissions::CLASSROOMS_VIEW]);
        $building = Building::factory()->create();

        $this->postJson('/api/v1/classrooms', ['name' => 'Room 204', 'building_id' => $building->id])
            ->assertCreated()
            ->assertJsonPath('data.building.id', $building->id);
    }
}
