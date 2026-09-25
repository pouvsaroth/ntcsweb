<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Http\Responses\ApiResponse;
use App\Models\Project;
use App\Models\Staff;
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

    /**
     * Who a task can be assigned to: active staff with a login account.
     * `id` is the staff member's user id — ProjectTask.assignee_id points at
     * users — labelled with their staff name. Gated on projects.view like
     * the board itself, so it doesn't depend on staff.view/users.view.
     */
    public function assignees(): JsonResponse
    {
        $this->authorize('viewAny', Project::class);

        $assignees = Staff::query()
            ->where('status', Staff::STATUS_ACTIVE)
            ->whereNotNull('user_id')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['user_id', 'first_name', 'last_name', 'employee_code'])
            ->map(fn (Staff $staff) => [
                'id' => $staff->user_id,
                'name' => $staff->fullName(),
                'employee_code' => $staff->employee_code,
            ]);

        return ApiResponse::success($assignees);
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
            $project->load([
                'creator', 'milestones',
                'columns.tasks.assignee', 'columns.tasks.creator', 'columns.tasks.milestone',
                'columns.tasks.labels', 'columns.tasks.checklistItems', 'columns.tasks.dependencies',
            ])
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
