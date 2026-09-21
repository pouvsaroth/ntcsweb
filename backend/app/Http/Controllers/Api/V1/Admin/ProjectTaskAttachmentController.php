<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectTaskAttachmentRequest;
use App\Http\Resources\ProjectTaskAttachmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProjectTask;
use App\Models\ProjectTaskAttachment;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

/** Reuses the existing `public` disk convention (Gallery/avatars, ExpenseAttachment) — see Tenant::storagePath(). */
final class ProjectTaskAttachmentController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /** Not eager-loaded with the board (unlike labels/checklist), since attachments only matter once the Edit Card modal is open — see ProjectTaskCommentController::index() for the same lazy-load shape. */
    public function index(ProjectTask $projectTask): JsonResponse
    {
        $this->authorize('view', $projectTask->project);

        return ApiResponse::success(
            ProjectTaskAttachmentResource::collection($projectTask->attachments()->with('uploadedBy')->get())
        );
    }

    public function store(StoreProjectTaskAttachmentRequest $request, ProjectTask $projectTask): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $this->context->getOrFail();
        $file = $request->file('file');

        $path = $file->store($tenant->storagePath('project-task-attachments'), 'public');

        $attachment = ProjectTaskAttachment::query()->create([
            'project_task_id' => $projectTask->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'uploaded_by' => $request->user()->getKey(),
        ]);

        return ApiResponse::created(new ProjectTaskAttachmentResource($attachment->load('uploadedBy')));
    }

    public function destroy(ProjectTask $projectTask, ProjectTaskAttachment $attachment): JsonResponse
    {
        $this->authorize('update', $projectTask->project);
        abort_unless($attachment->project_task_id === $projectTask->id, 404);

        Storage::disk('public')->delete($attachment->file_path);
        $attachment->delete();

        return ApiResponse::noContent();
    }
}
