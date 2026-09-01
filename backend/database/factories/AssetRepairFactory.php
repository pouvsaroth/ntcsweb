<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetRepair;
use App\Models\Tenant;
use App\Support\Assets\RepairStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetRepair>
 */
class AssetRepairFactory extends Factory
{
    protected $model = AssetRepair::class;

    public function definition(): array
    {
        return [
            'repair_number' => 'REP-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'asset_id' => Asset::factory(),
            'sent_date' => now()->toDateString(),
            'problem_description' => fake()->sentence(),
            'status' => RepairStatus::PENDING,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetRepair $repair) use ($tenant) {
            $repair->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
