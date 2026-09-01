<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\AssetLocation;
use App\Models\Department;
use App\Models\RepairShop;
use App\Models\Supplier;

/**
 * A minimal Asset catalog for a test tenant — mirrors HasChartOfAccounts:
 * real tests don't run any seeder (Assets has none, by design — see the
 * plan's "no universal default" note), they build just the handful of
 * config records a given test needs.
 */
trait HasAssetCatalog
{
    protected AssetCategory $computerCategory;

    protected AssetLocation $mainLocation;

    protected AssetLocation $labLocation;

    protected Department $itDepartment;

    protected Supplier $supplier;

    protected RepairShop $repairShop;

    protected function setUpAssetCatalog(): void
    {
        $this->computerCategory = AssetCategory::factory()->forTenant($this->tenant)->create(['code' => 'CMP', 'name' => 'Computers']);
        $this->mainLocation = AssetLocation::factory()->forTenant($this->tenant)->create(['code' => 'MAIN', 'name' => 'Main Campus']);
        $this->labLocation = AssetLocation::factory()->forTenant($this->tenant)->create(['code' => 'LAB1', 'name' => 'Computer Lab 1']);
        $this->itDepartment = Department::factory()->forTenant($this->tenant)->create(['code' => 'IT', 'name' => 'IT Department']);
        $this->supplier = Supplier::factory()->forTenant($this->tenant)->create(['name' => 'Dell Cambodia']);
        $this->repairShop = RepairShop::factory()->forTenant($this->tenant)->create(['name' => 'TechFix']);
    }

    /** @param array<string, mixed> $overrides */
    protected function createAsset(array $overrides = []): Asset
    {
        return Asset::factory()
            ->forTenant($this->tenant)
            ->forCategory($this->computerCategory)
            ->create([
                'location_id' => $this->mainLocation->id,
                'department_id' => $this->itDepartment->id,
                'supplier_id' => $this->supplier->id,
                ...$overrides,
            ]);
    }
}
