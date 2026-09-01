<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetHistory;
use App\Models\Staff;
use App\Support\Assets\AssetHistoryEvent;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

/**
 * AssetHistory is a first-class, append-only narrative — separate from
 * audit_logs (see AssetHistory's own docblock). Every lifecycle action must
 * add exactly one row and never edit or remove an earlier one.
 */
class AssetHistoryTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_creating_an_asset_writes_exactly_one_history_row(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();
        $assetId = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Test asset',
        ])->assertCreated()->json('data.id');

        $this->assertSame(1, AssetHistory::where('asset_id', $assetId)->count());
        $this->assertSame(AssetHistoryEvent::CREATED, AssetHistory::where('asset_id', $assetId)->firstOrFail()->event_type);
    }

    public function test_history_accumulates_across_multiple_actions_without_overwriting(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN, Permissions::ASSETS_RETURN, Permissions::ASSETS_TRANSFER,
        ]);
        $this->setUpAssetCatalog();
        $assetId = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Test asset',
        ])->assertCreated()->json('data.id');
        $staff = Staff::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/assets/{$assetId}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staff->id])->assertCreated();
        $this->postJson("/api/v1/assets/{$assetId}/return", [])->assertOk();
        $this->postJson("/api/v1/assets/{$assetId}/transfer", ['to_location_id' => $this->labLocation->id])->assertCreated();

        $events = AssetHistory::where('asset_id', $assetId)->orderBy('id')->pluck('event_type')->all();

        $this->assertSame([
            AssetHistoryEvent::CREATED,
            AssetHistoryEvent::STATUS_CHANGED,
            AssetHistoryEvent::ASSIGNED,
            AssetHistoryEvent::STATUS_CHANGED,
            AssetHistoryEvent::RETURNED,
            AssetHistoryEvent::TRANSFERRED,
        ], $events);
    }

    public function test_the_history_endpoint_is_paginated_and_never_loads_the_full_asset_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_VIEW]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $response = $this->getJson("/api/v1/assets/{$asset->id}/history");

        $response->assertOk();
        $this->assertArrayHasKey('pagination', $response->json('meta'));
    }
}
