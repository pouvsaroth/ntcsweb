<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectLabel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectLabel>
 */
class ProjectLabelFactory extends Factory
{
    protected $model = ProjectLabel::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(['Backend', 'Frontend', 'Database', 'DevOps', 'GPS', 'Reports', 'Bug', 'Feature']),
            'color' => null,
        ];
    }
}
