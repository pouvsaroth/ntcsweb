<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\MoveProjectTaskRequest;
use App\Http\Requests\Api\V1\Admin\StoreProjectTaskRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectTaskRequest;
use App\Http\Resources\AuditLogResource;
use App\Http\Resources\ProjectTaskResource;
use App\Http\Responses\ApiResponse;
use App\Models\AuditLog;
use App\Models\ProjectColumn;
use App\Models\ProjectTask;
use App\Services\Projects\ProjectTaskService;
use Illuminate\Http\JsonResponse;

final class ProjectTaskController extends Controller
{
    public function __construct(
        private readonly ProjectTaskService $tasks,
    ) {}

    public function store(StoreProjectTaskRequest $request, ProjectColumn $projectColumn): JsonResponse
    {
        $task = $this->tasks->create($projectColumn, $request->user(), $request->validated());

        return ApiResponse::created(new ProjectTaskResource($task->load(['assignee', 'creator'])));
    }

    public function update(UpdateProjectTaskRequest $request, ProjectTask $projectTask): JsonResponse
    {
        $projectTask->update($request->validated());

        return ApiResponse::success(new ProjectTaskResource($projectTask->load(['assignee', 'creator'])));
    }

    public function destroy(ProjectTask $projectTask): JsonResponse
    {
        $this->authorize('update', $projectTask->project);

        $projectTask->delete();

        return ApiResponse::noContent();
    }

    public function move(MoveProjectTaskRequest $request, ProjectTask $projectTask): JsonResponse
    {
        $destination = ProjectColumn::query()->findOrFail($request->validated('project_column_id'));

        $task = $this->tasks->move($projectTask, $destination, (int) $request->validated('order'));

        return ApiResponse::success(new ProjectTaskResource($task->load(['assignee', 'creator'])));
    }

    /**
     * The card's own activity trail — creation, column moves ("who moved it
     * to Progress/Review, when"), and field edits — sourced from the same
     * audit log every other Auditable model writes to (see ProjectTask's
     * auditExcept()/auditActionForDirty()/auditLabels() for how a move gets
     * a clean "Moved X from A to B" description here instead of a raw
     * column-id diff). Visible to anyone who can view the project, not
     * gated behind the separate sitewide audit-logs.view permission.
     */
    public function history(ProjectTask $projectTask): JsonResponse
    {
        $this->authorize('view', $projectTask->project);

        $entries = AuditLog::query()
            ->where('auditable_type', $projectTask->getMorphClass())
            ->where('auditable_id', $projectTask->getKey())
            ->with(['user', 'auditable'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(AuditLogResource::collection($entries));
    }
}
