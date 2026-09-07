<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ReplyToStudentFeedbackRequest;
use App\Http\Resources\StudentFeedbackResource;
use App\Http\Responses\ApiResponse;
use App\Models\StudentFeedback;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentFeedback::class);

        $query = StudentFeedback::query()->with(['student', 'teacher']);

        $feedback = ApiQuery::for($query, $request)
            ->filterable(['status', 'type', 'topic', 'student_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StudentFeedbackResource::collection($feedback));
    }

    public function show(StudentFeedback $studentFeedback): JsonResponse
    {
        $this->authorize('view', $studentFeedback);

        return ApiResponse::success(new StudentFeedbackResource(
            $studentFeedback->load(['student', 'teacher', 'replies.user'])
        ));
    }

    public function storeReply(ReplyToStudentFeedbackRequest $request, StudentFeedback $studentFeedback): JsonResponse
    {
        $studentFeedback->addReply($request->user(), $request->validated('body'));

        return ApiResponse::created(new StudentFeedbackResource(
            $studentFeedback->load(['student', 'teacher', 'replies.user'])
        ));
    }
}
