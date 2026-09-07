<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReorderProjectColumnsRequest;
use App\Http\Requests\Api\V1\Admin\StoreProjectColumnRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectColumnRequest;
use App\Http\Resources\ProjectColumnResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\ProjectColumn;
use App\Services\Projects\ProjectColumnService;
use Illuminate\Http\JsonResponse;

final class ProjectColumnController extends Controller
{
    public function __construct(
        private readonly ProjectColumnService $columns,
    ) {}

    public function store(StoreProjectColumnRequest $request, Project $project): JsonResponse
    {
        $column = $this->columns->create($project, $request->validated());

        return ApiResponse::created(new ProjectColumnResource($column));
    }

    public function update(UpdateProjectColumnRequest $request, ProjectColumn $projectColumn): JsonResponse
    {
        $projectColumn->update($request->validated());

        return ApiResponse::success(new ProjectColumnResource($projectColumn));
    }

    public function destroy(ProjectColumn $projectColumn): JsonResponse
    {
        $this->authorize('update', $projectColumn->project);

        $projectColumn->delete();

        return ApiResponse::noContent();
    }

    public function reorder(ReorderProjectColumnsRequest $request, Project $project): JsonResponse
    {
        $this->columns->reorder($project, $request->validated('column_ids'));

        return ApiResponse::success(ProjectColumnResource::collection($project->columns()->get()));
    }
}
