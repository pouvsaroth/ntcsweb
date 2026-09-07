<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectBoardTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_a_project_requires_the_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->postJson('/api/v1/projects', ['name' => 'Website Redesign'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_CREATE]);
        $response = $this->postJson('/api/v1/projects', ['name' => 'Website Redesign']);

        $response->assertCreated();
        $this->assertDatabaseHas('projects', ['name' => 'Website Redesign', 'tenant_id' => $this->tenant->id]);
    }

    public function test_it_lists_projects_for_the_current_tenant_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);

        Project::factory()->count(2)->create();
        $this->createForOtherTenant(fn () => Project::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->getJson('/api/v1/projects');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_adding_a_column_requires_the_update_permission_on_its_project(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();

        $this->postJson("/api/v1/projects/{$project->id}/columns", ['name' => 'To Do'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();

        $response = $this->postJson("/api/v1/projects/{$project->id}/columns", ['name' => 'To Do']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 0);
        $this->assertDatabaseHas('project_columns', ['project_id' => $project->id, 'name' => 'To Do']);
    }

    public function test_new_columns_append_to_the_end_of_the_board(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        ProjectColumn::factory()->create(['project_id' => $project->id, 'order' => 0]);
        ProjectColumn::factory()->create(['project_id' => $project->id, 'order' => 1]);

        $response = $this->postJson("/api/v1/projects/{$project->id}/columns", ['name' => 'Done']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 2);
    }

    public function test_reordering_columns_persists_the_new_order(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $first = ProjectColumn::factory()->create(['project_id' => $project->id, 'order' => 0]);
        $second = ProjectColumn::factory()->create(['project_id' => $project->id, 'order' => 1]);

        $response = $this->postJson("/api/v1/projects/{$project->id}/columns/reorder", [
            'column_ids' => [$second->id, $first->id],
        ]);

        $response->assertOk();
        $this->assertSame(0, $second->fresh()->order);
        $this->assertSame(1, $first->fresh()->order);
    }

    public function test_reordering_columns_rejects_a_list_that_does_not_match_the_projects_columns(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);

        $response = $this->postJson("/api/v1/projects/{$project->id}/columns/reorder", [
            'column_ids' => [$column->id, 999999],
        ]);

        $response->assertUnprocessable();
    }

    public function test_creating_a_task_appends_to_the_end_of_its_column(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 0]);

        $response = $this->postJson("/api/v1/project-columns/{$column->id}/tasks", ['title' => 'Design the homepage']);

        $response->assertCreated();
        $response->assertJsonPath('data.order', 1);
        $response->assertJsonPath('data.priority', ProjectTask::PRIORITY_MEDIUM);
    }

    public function test_moving_a_task_within_the_same_column_reindexes_every_sibling(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);

        $taskA = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 0]);
        $taskB = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 1]);
        $taskC = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 2]);

        // Move A (index 0) to index 2 — expected final order: B, C, A.
        $response = $this->postJson("/api/v1/project-tasks/{$taskA->id}/move", [
            'project_column_id' => $column->id,
            'order' => 2,
        ]);

        $response->assertOk();
        $this->assertSame(0, $taskB->fresh()->order);
        $this->assertSame(1, $taskC->fresh()->order);
        $this->assertSame(2, $taskA->fresh()->order);
    }

    public function test_moving_a_task_to_another_column_reindexes_both_columns(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $source = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $destination = ProjectColumn::factory()->create(['project_id' => $project->id]);

        $moving = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $source->id, 'order' => 0]);
        $stayingBehind = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $source->id, 'order' => 1]);
        $destTask = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $destination->id, 'order' => 0]);

        $response = $this->postJson("/api/v1/project-tasks/{$moving->id}/move", [
            'project_column_id' => $destination->id,
            'order' => 0,
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.project_column_id', $destination->id);
        $this->assertSame($destination->id, $moving->fresh()->project_column_id);
        $this->assertSame(0, $moving->fresh()->order);
        $this->assertSame(1, $destTask->fresh()->order);
        $this->assertSame(0, $stayingBehind->fresh()->order);
    }

    public function test_moving_a_task_into_a_column_from_another_project_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $projectA = Project::factory()->create();
        $projectB = Project::factory()->create();
        $columnA = ProjectColumn::factory()->create(['project_id' => $projectA->id]);
        $columnB = ProjectColumn::factory()->create(['project_id' => $projectB->id]);
        $task = ProjectTask::factory()->create(['project_id' => $projectA->id, 'project_column_id' => $columnA->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/move", [
            'project_column_id' => $columnB->id,
            'order' => 0,
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_project_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $other = $this->createForOtherTenant(fn () => Project::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/projects/{$other->id}")->assertNotFound();
    }

    public function test_deleting_a_project_requires_the_delete_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_DELETE]);
        $project = Project::factory()->create();

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();
        $this->assertSoftDeleted('projects', ['id' => $project->id]);
    }

    public function test_deleting_a_project_cascades_to_its_columns_and_tasks(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_DELETE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->deleteJson("/api/v1/projects/{$project->id}")->assertNoContent();

        $this->assertSoftDeleted('project_columns', ['id' => $column->id]);
        $this->assertSoftDeleted('project_tasks', ['id' => $task->id]);
    }

    public function test_a_task_can_be_assigned_to_a_tenant_user(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $assignee = User::factory()->forTenant($this->tenant)->create();

        $response = $this->postJson("/api/v1/project-columns/{$column->id}/tasks", [
            'title' => 'Draft the proposal',
            'assignee_id' => $assignee->id,
            'priority' => ProjectTask::PRIORITY_HIGH,
            'due_date' => now()->addWeek()->toDateString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.assignee', $assignee->name);
        $response->assertJsonPath('data.priority', ProjectTask::PRIORITY_HIGH);
    }
}
