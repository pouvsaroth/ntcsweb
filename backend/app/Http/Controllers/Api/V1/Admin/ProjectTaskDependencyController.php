<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectTaskDependencyRequest;
use App\Http\Resources\ProjectTaskSummaryResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProjectTask;
use Illuminate\Http\JsonResponse;

final class ProjectTaskDependencyController extends Controller
{
    public function store(StoreProjectTaskDependencyRequest $request, ProjectTask $projectTask): JsonResponse
    {
        $projectTask->dependencies()->attach($request->validated('depends_on_project_task_id'));

        return ApiResponse::created(ProjectTaskSummaryResource::collection($projectTask->dependencies()->get()));
    }

    public function destroy(ProjectTask $projectTask, ProjectTask $dependsOnProjectTask): JsonResponse
    {
        $this->authorize('update', $projectTask->project);

        $projectTask->dependencies()->detach($dependsOnProjectTask->id);

        return ApiResponse::noContent();
    }
}
