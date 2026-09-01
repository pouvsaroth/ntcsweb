<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetLocation;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AssetLocationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_an_admin_can_create_a_hierarchical_location(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);

        $campus = AssetLocation::factory()->forTenant($this->tenant)->create(['code' => 'MAIN', 'type' => AssetLocation::CAMPUS]);

        $response = $this->postJson('/api/v1/asset-locations', [
            'code' => 'LAB1',
            'name' => 'Computer Lab 1',
            'type' => AssetLocation::ROOM,
            'parent_id' => $campus->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.parent_id', $campus->id);
    }

    public function test_a_location_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        AssetLocation::factory()->forTenant($this->tenant)->create(['code' => 'LAB1']);

        $this->postJson('/api/v1/asset-locations', ['code' => 'LAB1', 'name' => 'Duplicate'])->assertUnprocessable();
    }

    public function test_viewing_locations_requires_the_assets_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/asset-locations')->assertForbidden();
    }
}
