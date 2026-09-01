<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Support\Assets\MaintenanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetMaintenanceTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_scheduling_maintenance_creates_a_numbered_record(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_MAINTENANCE_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/maintenance", [
            'maintenance_type' => 'Cleaning',
            'scheduled_date' => now()->addWeek()->toDateString(),
            'recurrence_interval_months' => 3,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', MaintenanceStatus::SCHEDULED);
        $response->assertJsonPath('data.maintenance_number', fn ($n) => str_starts_with($n, 'MNT-'.now()->year.'-'));
    }

    public function test_completing_maintenance_computes_the_next_maintenance_date_from_recurrence(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_MAINTENANCE_CREATE, Permissions::ASSET_MAINTENANCE_UPDATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $maintenanceId = $this->postJson("/api/v1/assets/{$asset->id}/maintenance", [
            'maintenance_type' => 'Servicing',
            'scheduled_date' => now()->toDateString(),
            'recurrence_interval_months' => 6,
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/asset-maintenance/{$maintenanceId}/complete", [
            'completed_date' => now()->toDateString(),
            'cost' => 15,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', MaintenanceStatus::COMPLETED);
        $response->assertJsonPath('data.next_maintenance_date', now()->addMonths(6)->toDateString());
    }

    public function test_completing_maintenance_without_recurrence_leaves_no_next_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_MAINTENANCE_CREATE, Permissions::ASSET_MAINTENANCE_UPDATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();

        $maintenanceId = $this->postJson("/api/v1/assets/{$asset->id}/maintenance", [
            'maintenance_type' => 'One-off inspection',
            'scheduled_date' => now()->toDateString(),
        ])->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/asset-maintenance/{$maintenanceId}/complete", []);

        $response->assertOk();
        $response->assertJsonPath('data.next_maintenance_date', null);
    }
}
