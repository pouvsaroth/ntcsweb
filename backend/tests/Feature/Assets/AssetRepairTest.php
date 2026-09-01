<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Models\Expense;
use App\Support\Accounting\ExpenseStatus;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssetStatus;
use App\Support\Assets\RepairStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAssetCatalog;
use Tests\Concerns\HasChartOfAccounts;
use Tests\TestCase;

class AssetRepairTest extends TestCase
{
    use HasAcademicAdmin, HasAssetCatalog, HasChartOfAccounts, RefreshDatabase;

    public function test_sending_an_asset_to_repair_moves_its_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_REPAIRS_CREATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);

        $response = $this->postJson("/api/v1/assets/{$asset->id}/repairs", [
            'repair_shop_id' => $this->repairShop->id,
            'problem_description' => 'Does not power on.',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', RepairStatus::SENT_TO_SHOP);
        $response->assertJsonPath('data.repair_number', fn ($n) => str_starts_with($n, 'REP-'.now()->year.'-'));
        $this->assertSame(AssetStatus::UNDER_REPAIR, $asset->fresh()->status);
    }

    public function test_repair_cost_is_always_computed_server_side_from_the_five_components(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_REPAIRS_CREATE, Permissions::ASSET_REPAIRS_UPDATE]);
        $this->setUpAssetCatalog();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);

        $repairId = $this->postJson("/api/v1/assets/{$asset->id}/repairs", ['repair_shop_id' => $this->repairShop->id])
            ->assertCreated()->json('data.id');

        $response = $this->putJson("/api/v1/asset-repairs/{$repairId}", [
            'diagnosis_cost' => 10,
            'parts_cost' => 50,
            'labor_cost' => 30,
            'transport_cost' => 5,
            'other_cost' => 0,
            'total_cost' => 999999, // must be ignored — never trusted from the client
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.total_cost', 95);
    }

    public function test_completing_a_repair_creates_a_pending_approval_expense_linked_to_it(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::ASSETS_CREATE, Permissions::ASSET_REPAIRS_CREATE, Permissions::ASSET_REPAIRS_COMPLETE,
        ]);
        $this->setUpAssetCatalog();
        $this->setUpChartOfAccounts();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);

        $repairId = $this->postJson("/api/v1/assets/{$asset->id}/repairs", ['repair_shop_id' => $this->repairShop->id])
            ->assertCreated()->json('data.id');

        $response = $this->postJson("/api/v1/asset-repairs/{$repairId}/complete", [
            'expense_account_id' => $this->electricityAccount->id,
            'parts_cost' => 50,
            'labor_cost' => 30,
            'condition_after_repair' => AssetCondition::GOOD,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.status', RepairStatus::REPAIR_COMPLETED);
        $response->assertJsonPath('data.total_cost', 80);

        $expense = Expense::query()->where('reference_type', \App\Models\AssetRepair::class)->where('reference_id', $repairId)->firstOrFail();
        $this->assertSame(ExpenseStatus::PENDING_APPROVAL, $expense->status);
        $this->assertSame('80.00', (string) $expense->amount);

        $this->assertSame(AssetCondition::GOOD, $asset->fresh()->condition);
        $this->assertSame(AssetStatus::REPAIR_COMPLETED, $asset->fresh()->status);
    }

    public function test_completing_a_repair_requires_the_complete_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ASSETS_CREATE, Permissions::ASSET_REPAIRS_CREATE]);
        $this->setUpAssetCatalog();
        $this->setUpChartOfAccounts();
        $asset = $this->createAsset(['status' => AssetStatus::BROKEN]);

        $repairId = $this->postJson("/api/v1/assets/{$asset->id}/repairs", ['repair_shop_id' => $this->repairShop->id])
            ->assertCreated()->json('data.id');

        $this->postJson("/api/v1/asset-repairs/{$repairId}/complete", ['expense_account_id' => $this->electricityAccount->id])
            ->assertForbidden();
    }
}
