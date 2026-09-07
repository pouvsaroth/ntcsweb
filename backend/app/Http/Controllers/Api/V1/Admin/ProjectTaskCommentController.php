<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProjectTaskCommentRequest;
use App\Http\Resources\ProjectTaskCommentResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProjectTask;
use App\Models\ProjectTaskComment;
use Illuminate\Http\JsonResponse;

final class ProjectTaskCommentController extends Controller
{
    public function index(ProjectTask $projectTask): JsonResponse
    {
        $this->authorize('view', $projectTask->project);

        return ApiResponse::success(
            ProjectTaskCommentResource::collection($projectTask->comments()->with('user')->get())
        );
    }

    public function store(StoreProjectTaskCommentRequest $request, ProjectTask $projectTask): JsonResponse
    {
        $comment = $projectTask->comments()->create([
            'user_id' => $request->user()->getKey(),
            'body' => $request->validated('body'),
        ]);

        return ApiResponse::created(new ProjectTaskCommentResource($comment->load('user')));
    }

    public function destroy(ProjectTaskComment $projectTaskComment): JsonResponse
    {
        $this->authorize('delete', $projectTaskComment);

        $projectTaskComment->delete();

        return ApiResponse::noContent();
    }
}
