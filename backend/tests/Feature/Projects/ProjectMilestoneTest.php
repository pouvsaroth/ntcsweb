<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectMilestoneTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_adding_a_milestone_requires_the_update_permission_on_its_project(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/milestones", ['name' => 'Sprint 1'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/milestones", ['name' => 'Sprint 1']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 0);
        $this->assertDatabaseHas('project_milestones', ['project_id' => $project->id, 'name' => 'Sprint 1'], 'tenant');
    }

    public function test_new_milestones_append_to_the_end(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        ProjectMilestone::factory()->create(['project_id' => $project->id, 'order' => 0]);

        $response = $this->postJson("/api/v1/projects/{$project->id}/milestones", ['name' => 'Sprint 2']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 1);
    }

    public function test_a_milestones_due_date_cannot_be_before_its_start_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/milestones", [
            'name' => 'Sprint 1',
            'start_date' => '2026-02-10',
            'due_date' => '2026-02-01',
        ]);

        $response->assertUnprocessable();
    }

    public function test_updating_a_milestone_requires_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);

        $response = $this->putJson("/api/v1/project-milestones/{$milestone->id}", ['name' => 'Renamed']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Renamed');
    }

    public function test_deleting_a_milestone_soft_deletes_it_and_leaves_referencing_tasks_alone(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = \App\Models\ProjectColumn::factory()->create(['project_id' => $project->id]);
        $milestone = ProjectMilestone::factory()->create(['project_id' => $project->id]);
        $task = \App\Models\ProjectTask::factory()->create([
            'project_id' => $project->id,
            'project_column_id' => $column->id,
            'project_milestone_id' => $milestone->id,
        ]);

        $this->deleteJson("/api/v1/project-milestones/{$milestone->id}")->assertNoContent();

        $this->assertSoftDeleted('project_milestones', ['id' => $milestone->id], 'tenant');
        $this->assertDatabaseHas('project_tasks', ['id' => $task->id, 'project_milestone_id' => $milestone->id], 'tenant');
    }
}
