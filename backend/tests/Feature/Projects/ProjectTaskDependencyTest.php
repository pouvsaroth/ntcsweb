<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectTaskDependencyTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_task_can_depend_on_another_task_in_the_same_project(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $blocker = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/dependencies", [
            'depends_on_project_task_id' => $blocker->id,
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('project_task_dependencies', [
            'project_task_id' => $task->id,
            'depends_on_project_task_id' => $blocker->id,
        ], 'tenant');
    }

    public function test_a_task_cannot_depend_on_itself(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/dependencies", [
            'depends_on_project_task_id' => $task->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('depends_on_project_task_id');
    }

    public function test_a_duplicate_dependency_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $blocker = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $task->dependencies()->attach($blocker->id);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/dependencies", [
            'depends_on_project_task_id' => $blocker->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_task_cannot_depend_on_a_task_from_another_project(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $columnA = ProjectColumn::factory()->create(['project_id' => $projectA->id]);
        $columnB = ProjectColumn::factory()->create(['project_id' => $projectB->id]);
        $task = ProjectTask::factory()->create(['project_id' => $projectA->id, 'project_column_id' => $columnA->id]);
        $other = ProjectTask::factory()->create(['project_id' => $projectB->id, 'project_column_id' => $columnB->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/dependencies", [
            'depends_on_project_task_id' => $other->id,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_dependency_can_be_removed(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $blocker = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $task->dependencies()->attach($blocker->id);

        $response = $this->deleteJson("/api/v1/project-tasks/{$task->id}/dependencies/{$blocker->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('project_task_dependencies', [
            'project_task_id' => $task->id,
            'depends_on_project_task_id' => $blocker->id,
        ], 'tenant');
    }

    public function test_adding_a_dependency_requires_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $blocker = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->postJson("/api/v1/project-tasks/{$task->id}/dependencies", [
            'depends_on_project_task_id' => $blocker->id,
        ])->assertForbidden();
    }
}
