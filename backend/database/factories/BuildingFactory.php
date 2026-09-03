<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Building;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Building>
 */
class BuildingFactory extends Factory
{
    protected $model = Building::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Main Building', 'Building A', 'Building B', 'Annex', 'Science Block']),
            'code' => null,
            'address' => fake()->streetAddress(),
            'status' => Building::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Building $building) use ($tenant) {
            $building->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
