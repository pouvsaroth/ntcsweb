<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTaskComment>
 */
class ProjectTaskCommentFactory extends Factory
{
    protected $model = ProjectTaskComment::class;

    public function definition(): array
    {
        return [
            'project_task_id' => ProjectTask::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
