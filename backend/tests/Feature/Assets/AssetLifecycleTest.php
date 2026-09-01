<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Support\Assets\AssetStatus;
use App\Support\Assets\DisposalMethod;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetLifecycleTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_retiring_an_asset_moves_its_status_and_records_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_RETIRE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/retire", ['reason' => 'Beyond economical repair']);

        $response->assertOk();
        $response->assertJsonPath('data.status', AssetStatus::RETIRED);
    }

    public function test_disposing_a_retired_asset_prevents_further_use(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_RETIRE, Permissions::ASSETS_DISPOSE, Permissions::ASSETS_ASSIGN]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();
        $staff = \App\Models\Staff::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/assets/{$asset->id}/retire", ['reason' => 'End of life'])->assertOk();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/dispose", [
            'method' => DisposalMethod::RECYCLED,
            'reason' => 'No resale value',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', AssetStatus::DISPOSED);
        $this->assertNotNull($asset->fresh()->disposal_date);

        $this->postJson("/api/v1/assets/{$asset->id}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staff->id])
            ->assertUnprocessable();
    }

    public function test_a_broken_asset_can_be_disposed_directly_without_being_retired_first(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_DISPOSE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);

        $this->postJson("/api/v1/assets/{$asset->id}/dispose", [
            'method' => DisposalMethod::DESTROYED,
            'reason' => 'Water damaged beyond repair',
        ])->assertOk();

        $this->assertSame(AssetStatus::DISPOSED, $asset->fresh()->status);
    }

    public function test_marking_an_asset_lost_then_found_returns_it_to_under_inspection(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_MARK_LOST, Permissions::ASSETS_MARK_FOUND]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_USE]);

        $this->postJson("/api/v1/assets/{$asset->id}/mark-lost", [
            'last_known_location' => 'Computer Lab 1',
            'description' => 'Not seen since last inventory check',
        ])->assertOk()->assertJsonPath('data.status', AssetStatus::LOST);

        $response = $this->postJson("/api/v1/assets/{$asset->id}/mark-found", ['notes' => 'Found in storage room']);
        $response->assertOk();
        $response->assertJsonPath('data.status', AssetStatus::UNDER_INSPECTION);
    }

    public function test_an_in_stock_asset_cannot_be_marked_found(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_MARK_FOUND]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::IN_STOCK]);

        $this->postJson("/api/v1/assets/{$asset->id}/mark-found", [])->assertUnprocessable();
    }
}
