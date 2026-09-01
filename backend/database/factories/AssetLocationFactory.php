<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetLocation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetLocation>
 */
class AssetLocationFactory extends Factory
{
    protected $model = AssetLocation::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('LOC???')),
            'name' => fake()->words(2, true),
            'type' => AssetLocation::ROOM,
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetLocation $location) use ($tenant) {
            $location->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forParent(AssetLocation $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }
}
