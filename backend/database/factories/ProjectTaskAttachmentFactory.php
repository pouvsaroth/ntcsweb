<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProjectTask;
use App\Models\ProjectTaskAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProjectTaskAttachment>
 */
class ProjectTaskAttachmentFactory extends Factory
{
    protected $model = ProjectTaskAttachment::class;

    public function definition(): array
    {
        return [
            'project_task_id' => ProjectTask::factory(),
            'file_path' => 'project-task-attachments/'.fake()->uuid().'.pdf',
            'file_name' => fake()->word().'.pdf',
            'mime_type' => 'application/pdf',
            'uploaded_by' => null,
        ];
    }
}
