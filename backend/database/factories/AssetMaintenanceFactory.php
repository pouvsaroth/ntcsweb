<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetMaintenance;
use App\Models\Tenant;
use App\Support\Assets\MaintenanceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetMaintenance>
 */
class AssetMaintenanceFactory extends Factory
{
    protected $model = AssetMaintenance::class;

    public function definition(): array
    {
        return [
            'maintenance_number' => 'MNT-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'asset_id' => Asset::factory(),
            'maintenance_type' => fake()->randomElement(['Cleaning', 'Inspection', 'Servicing', 'Calibration']),
            'scheduled_date' => now()->addMonth()->toDateString(),
            'status' => MaintenanceStatus::SCHEDULED,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetMaintenance $maintenance) use ($tenant) {
            $maintenance->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }
}
