<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Classroom;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Classroom>
 */
class ClassroomFactory extends Factory
{
    protected $model = Classroom::class;

    public function definition(): array
    {
        return [
            'name' => 'Room '.fake()->unique()->numberBetween(100, 599),
            'code' => null,
            'capacity' => fake()->numberBetween(10, 40),
            'location' => fake()->randomElement(['Main Building', 'Annex', 'Building B']),
            'status' => Classroom::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Classroom $classroom) use ($tenant) {
            $classroom->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
