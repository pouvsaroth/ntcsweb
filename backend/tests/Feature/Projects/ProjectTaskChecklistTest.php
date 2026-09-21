<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\ProjectTaskChecklistItem;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectTaskChecklistTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_adding_a_checklist_item_requires_the_update_permission_on_its_project(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->postJson("/api/v1/project-tasks/{$task->id}/checklist-items", ['title' => 'Write tests'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/checklist-items", ['title' => 'Write tests']);

        $response->assertCreated();
        $response->assertJsonPath('data.is_completed', false);
        $this->assertDatabaseHas('project_task_checklist_items', ['project_task_id' => $task->id, 'title' => 'Write tests'], 'tenant');
    }

    public function test_checklist_items_append_in_order(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id, 'order' => 0]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/checklist-items", ['title' => 'Second item']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 1);
    }

    public function test_an_item_can_be_marked_complete(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $item = ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id]);

        $response = $this->putJson("/api/v1/project-task-checklist-items/{$item->id}", ['is_completed' => true]);

        $response->assertOk();
        $response->assertJsonPath('data.is_completed', true);
    }

    public function test_the_task_resource_reports_checklist_progress(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id, 'is_completed' => true]);
        ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id, 'is_completed' => false]);
        ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id, 'is_completed' => true]);

        $response = $this->putJson("/api/v1/project-tasks/{$task->id}", ['title' => $task->title]);

        $response->assertOk();
        $response->assertJsonPath('data.checklist_progress.completed', 2);
        $response->assertJsonPath('data.checklist_progress.total', 3);
    }

    public function test_removing_a_checklist_item_requires_the_update_permission(): void
    {
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $item = ProjectTaskChecklistItem::factory()->create(['project_task_id' => $task->id]);

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $this->deleteJson("/api/v1/project-task-checklist-items/{$item->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $this->deleteJson("/api/v1/project-task-checklist-items/{$item->id}")->assertNoContent();
        $this->assertSoftDeleted('project_task_checklist_items', ['id' => $item->id], 'tenant');
    }
}
