<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectTaskCommentAndHistoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_user_who_can_update_the_project_can_comment_on_a_task(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/comments", ['body' => 'Looks good to me.']);

        $response->assertCreated();
        $response->assertJsonPath('data.body', 'Looks good to me.');
        $response->assertJsonPath('data.user_name', $this->admin->name);
        $this->assertDatabaseHas('project_task_comments', ['project_task_id' => $task->id, 'body' => 'Looks good to me.']);
    }

    public function test_commenting_requires_the_projects_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->postJson("/api/v1/project-tasks/{$task->id}/comments", ['body' => 'Nope'])->assertForbidden();
    }

    public function test_it_lists_comments_newest_first(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $first = ProjectTaskComment::factory()->create(['project_task_id' => $task->id, 'user_id' => $this->admin->id, 'body' => 'First', 'created_at' => now()->subMinute()]);
        $second = ProjectTaskComment::factory()->create(['project_task_id' => $task->id, 'user_id' => $this->admin->id, 'body' => 'Second']);

        $response = $this->getJson("/api/v1/project-tasks/{$task->id}/comments")->assertOk();

        $response->assertJsonPath('data.0.body', 'Second');
        $response->assertJsonPath('data.1.body', 'First');
    }

    public function test_a_comment_author_can_delete_their_own_comment_without_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $comment = ProjectTaskComment::factory()->create(['project_task_id' => $task->id, 'user_id' => $this->admin->id]);

        $this->deleteJson("/api/v1/project-task-comments/{$comment->id}")->assertNoContent();
        $this->assertSoftDeleted('project_task_comments', ['id' => $comment->id]);
    }

    public function test_a_different_user_cannot_delete_someone_elses_comment_without_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $otherUser = User::factory()->forTenant($this->tenant)->create();
        $comment = ProjectTaskComment::factory()->create(['project_task_id' => $task->id, 'user_id' => $otherUser->id]);

        $this->deleteJson("/api/v1/project-task-comments/{$comment->id}")->assertForbidden();
    }

    public function test_moving_a_task_records_a_readable_history_entry(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW, Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $todo = ProjectColumn::factory()->create(['project_id' => $project->id, 'name' => 'To Do']);
        $inProgress = ProjectColumn::factory()->create(['project_id' => $project->id, 'name' => 'In Progress']);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $todo->id, 'title' => 'Design homepage']);

        $this->postJson("/api/v1/project-tasks/{$task->id}/move", [
            'project_column_id' => $inProgress->id,
            'order' => 0,
        ])->assertOk();

        $response = $this->getJson("/api/v1/project-tasks/{$task->id}/history")->assertOk();

        $response->assertJsonPath('data.0.action', 'STATUS_CHANGE');
        $response->assertJsonPath('data.0.description', 'Moved "Design homepage" from To Do to In Progress');
        $response->assertJsonPath('data.0.user.id', $this->admin->id);
    }

    public function test_reordering_within_a_column_does_not_create_a_history_entry(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW, Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $taskA = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 0]);
        ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id, 'order' => 1]);

        $historyBefore = $this->getJson("/api/v1/project-tasks/{$taskA->id}/history")->json('data');

        $this->postJson("/api/v1/project-tasks/{$taskA->id}/move", [
            'project_column_id' => $column->id,
            'order' => 1,
        ])->assertOk();

        $historyAfter = $this->getJson("/api/v1/project-tasks/{$taskA->id}/history")->json('data');

        $this->assertCount(count($historyBefore), $historyAfter);
    }

    public function test_viewing_history_requires_viewing_the_project(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->getJson("/api/v1/project-tasks/{$task->id}/history")->assertForbidden();
    }
}
