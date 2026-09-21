<?php

declare(strict_types=1);

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAttachment;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ProjectTaskAttachmentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_uploading_an_attachment_requires_the_update_permission_on_its_project(): void
    {
        Storage::fake('public');

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_VIEW]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $this->postJson("/api/v1/project-tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
        ])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.file_name', 'spec.pdf');
        $this->assertDatabaseHas('project_task_attachments', ['project_task_id' => $task->id, 'file_name' => 'spec.pdf'], 'tenant');
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('huge.pdf', 20000, 'application/pdf'),
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_disallowed_file_type_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $response = $this->postJson("/api/v1/project-tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload'),
        ]);

        $response->assertUnprocessable();
    }

    public function test_removing_an_attachment_deletes_the_stored_file(): void
    {
        Storage::fake('public');

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $task = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);

        $upload = $this->postJson("/api/v1/project-tasks/{$task->id}/attachments", [
            'file' => UploadedFile::fake()->create('spec.pdf', 100, 'application/pdf'),
        ])->json('data');

        $attachment = ProjectTaskAttachment::query()->findOrFail($upload['id']);
        Storage::disk('public')->assertExists($attachment->file_path);

        $this->deleteJson("/api/v1/project-tasks/{$task->id}/attachments/{$attachment->id}")->assertNoContent();

        Storage::disk('public')->assertMissing($attachment->file_path);
        $this->assertDatabaseMissing('project_task_attachments', ['id' => $attachment->id], 'tenant');
    }

    public function test_an_attachment_from_another_task_returns_not_found(): void
    {
        Storage::fake('public');

        $this->actingAsAdminWithPermissions([Permissions::PROJECTS_UPDATE]);
        $project = Project::factory()->create();
        $column = ProjectColumn::factory()->create(['project_id' => $project->id]);
        $taskA = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $taskB = ProjectTask::factory()->create(['project_id' => $project->id, 'project_column_id' => $column->id]);
        $attachment = ProjectTaskAttachment::factory()->create(['project_task_id' => $taskA->id]);

        $this->deleteJson("/api/v1/project-tasks/{$taskB->id}/attachments/{$attachment->id}")->assertNotFound();
    }
}
