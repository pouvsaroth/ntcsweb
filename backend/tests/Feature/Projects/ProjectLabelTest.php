<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectLabel;
use App\Models\ProjectTask;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectLabelTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_a_label_requires_the_projects_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);

        $this->postJson('/api/v1/project-labels', ['name' => 'Backend'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $response = $this->postJson('/api/v1/project-labels', ['name' => 'Backend']);

        $response->assertCreated();
        $this->assertDatabaseHas('project_labels', ['name' => 'Backend'], 'tenant');
    }

    public function test_label_names_must_be_unique(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        ProjectLabel::factory()->create(['name' => 'Bug']);

        $this->postJson('/api/v1/project-labels', ['name' => 'Bug'])->assertUnprocessable();
    }

    public function test_anyone_who_can_view_projects_can_list_labels(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        ProjectLabel::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/project-labels');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_a_task_can_be_created_with_labels(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $labels = ProjectLabel::factory()->count(2)->create();

        $response = $this->postJson("/api/v1/project-columns/{$column->id}/tasks", [
            'title' => 'Fix the login bug',
            'label_ids' => $labels->pluck('id')->all(),
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.labels');
    }

    public function test_updating_a_tasks_labels_replaces_the_previous_set(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $oldLabel = ProjectLabel::factory()->create();
        $newLabel = ProjectLabel::factory()->create();
        $task->labels()->attach($oldLabel->id);

        $response = $this->putJson("/api/v1/project-tasks/{$task->id}", ['label_ids' => [$newLabel->id]]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.labels');
        $response->assertJsonPath('data.labels.0.id', $newLabel->id);
    }

    public function test_deleting_a_label_requires_the_projects_update_permission(): void
    {
        $label = ProjectLabel::factory()->create();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $this->deleteJson("/api/v1/project-labels/{$label->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $this->deleteJson("/api/v1/project-labels/{$label->id}")->assertNoContent();
        $this->assertSoftDeleted('project_labels', ['id' => $label->id], 'tenant');
    }
}
