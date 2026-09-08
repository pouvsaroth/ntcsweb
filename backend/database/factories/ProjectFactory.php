<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    protected $model = Project::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->catchPhrase(),
            'description' => fake()->optional()->sentence(),
            'status' => Project::STATUS_ACTIVE,
        ];
    }
}
