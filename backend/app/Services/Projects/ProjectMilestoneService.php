<?php

declare(strict_types=1);

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;

final class ProjectMilestoneService
{
    public function create(Project $project, array $data): ProjectMilestone
    {
        $maxOrder = $project->milestones()->max('order');
        $nextOrder = $maxOrder === null ? 0 : $maxOrder + 1;

        return $project->milestones()->create([
            'name' => $data['name'],
            'start_date' => $data['start_date'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            'order' => $nextOrder,
        ]);
    }
}
