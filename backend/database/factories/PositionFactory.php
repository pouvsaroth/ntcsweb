<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Position;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Position>
 */
class PositionFactory extends Factory
{
    protected $model = Position::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->jobTitle(),
            'role_id' => Role::factory(),
            'description' => fake()->sentence(),
            'status' => Position::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => Position::STATUS_INACTIVE]);
    }
}
