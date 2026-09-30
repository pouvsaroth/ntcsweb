<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ExamApplication;
use App\Support\Academic\ExamMention;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my scores" — every exam score entered for the
 * signed-in student, newest exam first. Identity-gated through
 * `$user->student`, same pattern as MyAttendanceController; no permission.
 * Only scoreable applications (approved or make-up, see
 * ExamApplication::scopeScoreable()) that actually have a score.
 */
final class MyExamScoreController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $student = $request->user()?->student;

        if ($student === null) {
            throw ValidationException::withMessages([
                'student' => 'This account is not linked to a student record.',
            ]);
        }

        $applications = ExamApplication::query()
            ->where('student_id', $student->id)
            ->scoreable()
            ->has('score')
            ->with(['book', 'score', 'enrollment.coursePackage'])
            ->orderByDesc('exam_date')
            ->orderByDesc('id')
            ->get();

        return ApiResponse::success($applications->map(fn (ExamApplication $application) => [
            'exam_application_id' => $application->id,
            'book' => $application->book?->title,
            'course_package' => $application->enrollment?->coursePackage?->name,
            'exam_date' => $application->exam_date?->toDateString(),
            'score' => (float) $application->score->score,
            'mention' => ExamMention::for((float) $application->score->score),
            'is_make_up' => $application->status === ExamApplication::STATUS_MAKE_UP,
        ])->values());
    }
}
