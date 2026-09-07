<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $projects = ApiQuery::for(Project::query()->withCount('tasks')->with('creator'), $request)
            ->filterable(['status'])
            ->sortable(['name', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ProjectResource::collection($projects));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::query()->create([
            ...$request->validated(),
            'created_by' => Auth::id(),
        ]);

        return ApiResponse::created(new ProjectResource($project->load('creator')));
    }

    public function show(Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return ApiResponse::success(new ProjectResource(
            $project->load(['creator', 'columns.tasks.assignee', 'columns.tasks.creator'])
        ));
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->update($request->validated());

        return ApiResponse::success(new ProjectResource($project->load('creator')));
    }

    public function destroy(Project $project): JsonResponse
    {
        $this->authorize('delete', $project);

        $project->delete();

        return ApiResponse::noContent();
    }
}
