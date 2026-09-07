<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyStudentFeedbackRequest;
use App\Http\Requests\Api\V1\StoreMyStudentFeedbackReplyRequest;
use App\Http\Resources\StudentFeedbackResource;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentFeedback;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my requests and comments," not "everyone's." A
 * student may submit a request or comment about the school or about one of
 * their own teachers, see their own thread (with any staff replies), and
 * post a follow-up message into it. Identity-gated through `$user->student`,
 * the same pattern as MyLeaveRequestController — no permission is required
 * or checked here.
 */
final class MyStudentFeedbackController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = StudentFeedback::query()->where('student_id', $student->id)->with(['teacher', 'replies.user']);

        $feedback = ApiQuery::for($query, $request)
            ->filterable(['status', 'type', 'topic'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StudentFeedbackResource::collection($feedback));
    }

    public function store(StoreMyStudentFeedbackRequest $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $feedback = StudentFeedback::query()->create([
            ...$request->validated(),
            'student_id' => $student->id,
        ]);

        return ApiResponse::created(new StudentFeedbackResource($feedback->load(['teacher', 'replies.user'])));
    }

    public function storeReply(StoreMyStudentFeedbackReplyRequest $request, StudentFeedback $studentFeedback): JsonResponse
    {
        $studentFeedback->addReply($request->user(), $request->validated('body'));

        return ApiResponse::created(new StudentFeedbackResource($studentFeedback->load(['teacher', 'replies.user'])));
    }

    /**
     * The teachers eligible for the "about a teacher" picker — only the
     * ones actually teaching one of this student's active enrollments, not
     * the tenant's whole staff directory. See Student::teacherIds().
     */
    public function teachers(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $teachers = Staff::query()
            ->whereIn('id', $student->teacherIds())
            ->get(['id', 'first_name', 'last_name'])
            ->map(fn (Staff $teacher) => ['id' => $teacher->id, 'name' => $teacher->fullName()])
            ->values();

        return ApiResponse::success($teachers);
    }

    private function studentOrFail(Request $request): Student
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        return $student;
    }
}
