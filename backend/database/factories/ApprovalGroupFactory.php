<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalGroup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalGroup>
 */
class ApprovalGroupFactory extends Factory
{
    protected $model = ApprovalGroup::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->optional()->sentence(),
        ];
    }
}
