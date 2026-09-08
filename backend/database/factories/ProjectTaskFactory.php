<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTask>
 */
class ProjectTaskFactory extends Factory
{
    protected $model = ProjectTask::class;

    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'project_column_id' => ProjectColumn::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'priority' => ProjectTask::PRIORITY_MEDIUM,
            'order' => 0,
        ];
    }
}
