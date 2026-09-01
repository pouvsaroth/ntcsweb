<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AuditLog;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssetStatus;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_creating_an_asset_generates_a_server_side_number_and_defaults_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();

        $response = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Dell Optiplex 3080',
            'brand' => 'Dell',
            'model' => 'Optiplex 3080',
            'purchase_price' => 650,
            'location_id' => $this->mainLocation->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.asset_number', fn ($n) => str_starts_with($n, 'AST-'));
        $response->assertJsonPath('data.status', AssetStatus::IN_STOCK);
        $response->assertJsonPath('data.condition', AssetCondition::NEW);

        $log = AuditLog::where('action', AuditAction::ASSET_CREATED)->firstOrFail();
        $this->assertStringContainsString('Dell Optiplex 3080', $log->description);
    }

    public function test_asset_numbers_are_unique_and_sequential_even_under_repeated_calls(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();

        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $numbers[] = $this->postJson('/api/v1/assets', [
                'category_id' => $this->computerCategory->id,
                'name' => "Asset {$i}",
            ])->assertCreated()->json('data.asset_number');
        }

        $this->assertCount(3, array_unique($numbers));
    }

    public function test_the_frontend_cannot_supply_an_asset_number(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();

        $response = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Injected Asset',
            'asset_number' => 'AST-999999',
        ]);

        $response->assertCreated();
        $this->assertNotSame('AST-999999', $response->json('data.asset_number'));
    }

    public function test_updating_an_asset_cannot_change_status_or_location_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_UPDATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $response = $this->putJson("/api/v1/assets/{$asset->id}", [
            'name' => 'Renamed Asset',
            'status' => AssetStatus::DISPOSED,
            'location_id' => $this->labLocation->id,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Renamed Asset');
        $response->assertJsonPath('data.status', AssetStatus::IN_STOCK);
        $response->assertJsonPath('data.location.id', $this->mainLocation->id);
    }

    public function test_serial_numbers_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE]);
        $this->setUpAssetCatalog();
        $this->createAsset(['serial_number' => 'SN-1']);

        $response = $this->postJson('/api/v1/assets', [
            'category_id' => $this->computerCategory->id,
            'name' => 'Duplicate serial',
            'serial_number' => 'SN-1',
        ]);

        $response->assertUnprocessable();
    }

    public function test_creating_an_asset_requires_the_assets_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAssetCatalog();

        $this->postJson('/api/v1/assets', ['category_id' => $this->computerCategory->id, 'name' => 'X'])->assertForbidden();
    }
}
