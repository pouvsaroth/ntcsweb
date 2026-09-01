<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\AssetAssignment;
use App\Models\Staff;
use App\Support\Assets\AssetStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\TestCase;

class AssetAssignmentTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, RefreshDatabase;

    public function test_assigning_an_asset_to_staff_moves_status_and_records_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN, Permissions::ASSETS_VIEW]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();
        $staff = Staff::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/assign", [
            'assignable_type' => 'staff',
            'assignable_id' => $staff->id,
        ]);

        $response->assertCreated();
        $this->assertSame(AssetStatus::ASSIGNED, $asset->fresh()->status);

        $history = $this->getJson("/api/v1/assets/{$asset->id}/history")->assertOk();
        $this->assertTrue(collect($history->json('data'))->contains(fn ($row) => $row['event_type'] === 'ASSIGNED'));
    }

    public function test_reassigning_closes_the_previous_assignment_without_deleting_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();
        $staffOne = Staff::factory()->forTenant($this->tenant)->create();
        $staffTwo = Staff::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/assets/{$asset->id}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staffOne->id])->assertCreated();
        $firstAssignmentId = AssetAssignment::where('asset_id', $asset->id)->firstOrFail()->id;

        $this->postJson("/api/v1/assets/{$asset->id}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staffTwo->id])->assertCreated();

        $this->assertSame(2, AssetAssignment::where('asset_id', $asset->id)->count());
        $first = AssetAssignment::find($firstAssignmentId);
        $this->assertSame(AssetAssignment::RETURNED, $first->status);
        $this->assertNotNull($first->returned_date);

        $active = AssetAssignment::where('asset_id', $asset->id)->active()->firstOrFail();
        $this->assertSame($staffTwo->id, $active->assignable_id);
    }

    public function test_returning_an_asset_moves_it_back_to_in_stock(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN, Permissions::ASSETS_RETURN]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();
        $staff = Staff::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/assets/{$asset->id}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staff->id])->assertCreated();

        $response = $this->postJson("/api/v1/assets/{$asset->id}/return", ['condition_at_return' => 'GOOD']);
        $response->assertOk();
        $response->assertJsonPath('data.status', AssetStatus::IN_STOCK);

        $assignment = AssetAssignment::where('asset_id', $asset->id)->firstOrFail();
        $this->assertSame(AssetAssignment::RETURNED, $assignment->status);
    }

    public function test_a_retired_asset_cannot_be_assigned(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSETS_ASSIGN, Permissions::ASSETS_RETIRE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset();
        $staff = Staff::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/assets/{$asset->id}/retire", ['reason' => 'End of life'])->assertOk();

        $this->postJson("/api/v1/assets/{$asset->id}/assign", ['assignable_type' => 'staff', 'assignable_id' => $staff->id])
            ->assertUnprocessable();
    }
}
