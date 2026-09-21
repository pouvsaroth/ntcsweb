<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectTaskChecklistItemRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProjectTaskChecklistItemRequest;
use App\Http\Resources\ProjectTaskChecklistItemResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProjectTask;
use App\Models\ProjectTaskChecklistItem;
use Illuminate\Http\JsonResponse;

final class ProjectTaskChecklistItemController extends Controller
{
    public function store(StoreProjectTaskChecklistItemRequest $request, ProjectTask $projectTask): JsonResponse
    {
        $maxOrder = $projectTask->checklistItems()->max('order');

        $item = $projectTask->checklistItems()->create([
            'title' => $request->validated('title'),
            'order' => $maxOrder === null ? 0 : $maxOrder + 1,
        ]);

        return ApiResponse::created(new ProjectTaskChecklistItemResource($item));
    }

    public function update(UpdateProjectTaskChecklistItemRequest $request, ProjectTaskChecklistItem $projectTaskChecklistItem): JsonResponse
    {
        $projectTaskChecklistItem->update($request->validated());

        return ApiResponse::success(new ProjectTaskChecklistItemResource($projectTaskChecklistItem));
    }

    public function destroy(ProjectTaskChecklistItem $projectTaskChecklistItem): JsonResponse
    {
        $this->authorize('update', $projectTaskChecklistItem->task->project);

        $projectTaskChecklistItem->delete();

        return ApiResponse::noContent();
    }
}
