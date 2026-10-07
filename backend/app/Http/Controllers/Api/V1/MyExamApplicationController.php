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

        // book/classroom/table/student are the same "what does the admin's
        // Examination tab show" detail a student sees for their own rows —
        // ExamApplicationResource already renders all of it, it just needs
        // these eager-loaded (whenLoaded() otherwise silently omits them).
        // DRAFT is excluded — a "Send to Exam" row nobody has applied to
        // yet isn't the student's own submission until they actually apply
        // (see applyOnline()); showing it here before that would just be
        // confusing.
        $query = ExamApplication::query()->where('student_id', $student->id)
            ->where('status', '!=', ExamApplication::STATUS_DRAFT)
            ->with('enrollment.coursePackage', 'enrollment.schoolClass', 'book', 'classroom', 'table', 'student');

        $applications = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['exam_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ExamApplicationResource::collection($applications));
    }

    public function store(StoreMyExamApplicationRequest $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $application = $this->examApplications->applyOnline(
            $student,
            (int) $request->validated('enrollment_id'),
            $request->safe()->only(['first_name', 'last_name', 'english_name', 'gender', 'date_of_birth', 'phone', 'village_code']),
            $request->file('photo'),
        );

        return ApiResponse::created(new ExamApplicationResource(
            $application->load('enrollment.coursePackage', 'enrollment.schoolClass', 'book', 'classroom', 'table', 'student')
        ));
    }

    /**
     * The student's own active enrollments, each carrying the course/class
     * that an ExamApplication's `enrollment_id` picker needs — one selection
     * instead of two independent dropdowns. Only an enrollment a teacher
     * has sent to exam (its application is still a draft) — a student can't
     * apply for a course nobody sent them to exam for, and once they've
     * applied (pending) or it's decided there's nothing left to apply for.
     * See lookup() below.
     */
    public function enrollments(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $sentToExamEnrollmentIds = ExamApplication::query()
            ->where('student_id', $student->id)
            ->where('status', ExamApplication::STATUS_DRAFT)
            ->pluck('enrollment_id');

        $enrollments = $student->enrollments()
            ->active()
            ->whereIn('id', $sentToExamEnrollmentIds)
            ->with('coursePackage', 'schoolClass')
            ->get()
            ->map(fn ($enrollment) => [
                'id' => $enrollment->id,
                'course_package' => $enrollment->coursePackage !== null
                    ? ['id' => $enrollment->coursePackage->id, 'name' => $enrollment->coursePackage->name]
                    : null,
                'school_class' => $enrollment->schoolClass !== null
                    ? ['id' => $enrollment->schoolClass->id, 'name' => $enrollment->schoolClass->name]
                    : null,
            ])
            ->values();

        return ApiResponse::success($enrollments);
    }

    /**
     * Mirrors the admin Application Form's "Show Data" lookup (see
     * Admin\ExamApplicationController::lookup()) but scoped to one of this
     * student's own enrollments instead of an arbitrary Enrollment Code —
     * the personal-info section it returns is editable by the student, the
     * exam_application block (if any) is display-only on the frontend,
     * since only a teacher/admin ever sets book/room/table/date.
     */
    public function lookup(Request $request, int $enrollment): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $enrollmentModel = $this->examApplications->lookupForStudent($enrollment);

        if ($enrollmentModel->student_id !== $student->id) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'This enrollment does not belong to you.',
            ]);
        }

        $existing = $enrollmentModel->getRelation('latestExamApplication');

        if ($existing?->status !== ExamApplication::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'Your teacher has not sent this course to exam.',
            ]);
        }

        return ApiResponse::success([
            'enrollment_id' => $enrollmentModel->id,
            'student' => [
                'first_name' => $student->first_name,
                'last_name' => $student->last_name,
                'english_name' => $student->english_name,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->toDateString(),
                'phone' => $student->phone,
                'village_code' => $student->village_code,
                'address' => $student->fullAddress(),
                'photo_url' => $student->photoUrl(),
            ],
            'course_package' => $enrollmentModel->coursePackage !== null
                ? ['id' => $enrollmentModel->coursePackage->id, 'name' => $enrollmentModel->coursePackage->name]
                : null,
            'exam_application' => $existing !== null ? new ExamApplicationResource($existing) : null,
        ]);
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
