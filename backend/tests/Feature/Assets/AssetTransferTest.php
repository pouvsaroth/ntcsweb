<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetTransferTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_transferring_an_asset_updates_its_location_and_records_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_TRANSFER, Permissions::ASSETS_VIEW]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/transfer", [
            'to_location_id' => $this->labLocation->id,
            'reason' => 'Moved to computer lab',
        ]);

        $response->assertCreated();
        $this->assertSame($this->labLocation->id, $asset->fresh()->location_id);

        $history = $this->getJson("/api/v1/assets/{$asset->id}/history")->assertOk();
        $this->assertTrue(collect($history->json('data'))->contains(fn ($row) => $row['event_type'] === 'TRANSFERRED'));

        $transfers = $this->getJson("/api/v1/assets/{$asset->id}/transfers")->assertOk();
        $this->assertSame($this->mainLocation->name, collect($transfers->json('data'))->first()['from_location']);
        $this->assertSame($this->labLocation->name, collect($transfers->json('data'))->first()['to_location']);
    }

    public function test_transferring_requires_the_assets_transfer_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $this->postJson("/api/v1/assets/{$asset->id}/transfer", ['to_location_id' => $this->labLocation->id])->assertForbidden();
    }
}
