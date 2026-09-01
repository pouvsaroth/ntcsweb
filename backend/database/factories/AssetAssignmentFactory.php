<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetAssignment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;

/**
 * @extends Factory<AssetAssignment>
 */
class AssetAssignmentFactory extends Factory
{
    protected $model = AssetAssignment::class;

    public function definition(): array
    {
        return [
            'asset_id' => Asset::factory(),
            'assigned_date' => now()->toDateString(),
            'status' => AssetAssignment::ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetAssignment $assignment) use ($tenant) {
            $assignment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forAsset(Asset $asset): static
    {
        return $this->state(['asset_id' => $asset->getKey()]);
    }

    public function assignedTo(Model $assignable): static
    {
        return $this->state(['assignable_type' => $assignable->getMorphClass(), 'assignable_id' => $assignable->getKey()]);
    }

    public function returned(): static
    {
        return $this->state(['status' => AssetAssignment::RETURNED, 'returned_date' => now()->toDateString()]);
    }
}
