<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RejectStudentRegistrationRequest;
use App\Http\Resources\StudentRegistrationResource;
use App\Http\Responses\ApiResponse;
use App\Models\Student;
use App\Notifications\Academic\StudentRegistrationApprovedNotification;
use App\Services\Academic\StudentRegistrationService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The "Student Registration Pending" queue — self-registered students
 * (Student::STATUS_PENDING) awaiting confirmation that their cash payment
 * arrived. See StudentRegistrationService for what approve()/reject()
 * actually do.
 */
final class StudentRegistrationController extends Controller
{
    public function __construct(private readonly StudentRegistrationService $registrations) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Student::class);

        $registrations = ApiQuery::for(
            Student::query()->where('status', Student::STATUS_PENDING)
                ->with(['enrollments.schoolClass', 'enrollments.coursePackage', 'enrollments.academicProgram', 'invoices']),
            $request,
        )
            ->searchable('first_name', 'last_name', 'student_code', 'phone')
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(StudentRegistrationResource::collection($registrations));
    }

    public function show(Student $student): JsonResponse
    {
        $this->authorize('view', $student);

        return ApiResponse::success(new StudentRegistrationResource(
            $student->load(['enrollments.schoolClass', 'enrollments.coursePackage', 'enrollments.academicProgram', 'invoices'])
        ));
    }

    public function approve(Request $request, Student $student): JsonResponse
    {
        $this->authorize('approveRegistration', $student);

        $student = $this->registrations->approve($student, $request->user());

        $student->user?->notify(new StudentRegistrationApprovedNotification($student));

        return ApiResponse::success(new StudentRegistrationResource(
            $student->load(['enrollments.schoolClass', 'enrollments.coursePackage', 'enrollments.academicProgram', 'invoices'])
        ));
    }

    public function reject(RejectStudentRegistrationRequest $request, Student $student): JsonResponse
    {
        $student = $this->registrations->reject($student, $request->validated('reason'), $request->user());

        return ApiResponse::success(new StudentRegistrationResource(
            $student->load(['enrollments.schoolClass', 'enrollments.coursePackage', 'enrollments.academicProgram', 'invoices'])
        ));
    }
}
