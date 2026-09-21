<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectTask;
use App\Models\ProjectTaskChecklistItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTaskChecklistItem>
 */
class ProjectTaskChecklistItemFactory extends Factory
{
    protected $model = ProjectTaskChecklistItem::class;

    public function definition(): array
    {
        return [
            'project_task_id' => ProjectTask::factory(),
            'title' => fake()->sentence(3),
            'is_completed' => false,
            'order' => 0,
        ];
    }
}
