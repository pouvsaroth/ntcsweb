<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectMilestoneRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectMilestoneRequest;
use App\Http\Resources\ProjectMilestoneResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Services\Projects\ProjectMilestoneService;
use Illuminate\Http\JsonResponse;

final class ProjectMilestoneController extends Controller
{
    public function __construct(
        private readonly ProjectMilestoneService $milestones,
    ) {}

    public function store(StoreProjectMilestoneRequest $request, Project $project): JsonResponse
    {
        $milestone = $this->milestones->create($project, $request->validated());

        return ApiResponse::created(new ProjectMilestoneResource($milestone));
    }

    public function update(UpdateProjectMilestoneRequest $request, ProjectMilestone $projectMilestone): JsonResponse
    {
        $projectMilestone->update($request->validated());

        return ApiResponse::success(new ProjectMilestoneResource($projectMilestone));
    }

    public function destroy(ProjectMilestone $projectMilestone): JsonResponse
    {
        $this->authorize('update', $projectMilestone->project);

        $projectMilestone->delete();

        return ApiResponse::noContent();
    }
}
