<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyExamApplicationRequest;
use App\Http\Resources\ExamApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExamApplication;
use App\Models\Student;
use App\Services\Academic\ExamApplicationService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Student self-service: "my exam applications," not "everyone's." A
 * student may apply for an exam against one of their own active
 * enrollments, see their own list, and check the current exam fee before
 * applying. Identity-gated through `$user->student`, the same pattern as
 * MyLeaveRequestController — no permission is required or checked here.
 */
final class MyExamApplicationController extends Controller
{
    public function __construct(
        private readonly ExamApplicationService $examApplications,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = ExamApplication::query()->where('student_id', $student->id)->with('enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book');

        $applications = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['exam_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ExamApplicationResource::collection($applications));
    }

    public function store(StoreMyExamApplicationRequest $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $application = $this->examApplications->submit($student, $request->validated());

        return ApiResponse::created(new ExamApplicationResource(
            $application->load('enrollment.coursePackage', 'enrollment.schoolClass', 'enrollment.book')
        ));
    }

    /**
     * The student's own active enrollments, each carrying the
     * course/class/book that an ExamApplication's `enrollment_id` picker
     * needs — one selection instead of three independent dropdowns.
     */
    public function enrollments(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $enrollments = $student->enrollments()
            ->active()
            ->with('coursePackage', 'schoolClass', 'book')
            ->get()
            ->map(fn ($enrollment) => [
                'id' => $enrollment->id,
                'course_package' => $enrollment->coursePackage !== null
                    ? ['id' => $enrollment->coursePackage->id, 'name' => $enrollment->coursePackage->name]
                    : null,
                'school_class' => $enrollment->schoolClass !== null
                    ? ['id' => $enrollment->schoolClass->id, 'name' => $enrollment->schoolClass->name]
                    : null,
                'book' => $enrollment->book !== null
                    ? ['id' => $enrollment->book->id, 'name' => $enrollment->book->title]
                    : null,
            ])
            ->values();

        return ApiResponse::success($enrollments);
    }

    /**
     * The tenant's current exam fee — `amount` is null when the school
     * hasn't configured one yet, which the frontend uses to block
     * submission with a clear message rather than letting the form 422 on
     * submit.
     */
    public function fee(): JsonResponse
    {
        $tenant = $this->context->getOrFail();

        return ApiResponse::success([
            'amount' => $tenant->exam_fee_amount,
            'currency' => $tenant->default_currency,
        ]);
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
