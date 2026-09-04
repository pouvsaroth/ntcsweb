<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\CancelEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\ChangeEnrollmentStatusRequest;
use App\Http\Requests\Api\V1\Admin\StoreEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\TransferEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Resources\EnrollmentStatusHistoryResource;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Academic\EnrollmentService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    private const WITH = ['student', 'schoolClass', 'table', 'book', 'coursePackage', 'academicProgram', 'studyMode', 'invoiceItems.invoice'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $enrollments = ApiQuery::for(
            Enrollment::query()->with(self::WITH),
            $request,
        )
            ->filterable(['status', 'student_id', 'class_id', 'course_package_id', 'academic_program_id'])
            ->sortable(['enrolled_at', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(EnrollmentResource::collection($enrollments));
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = Enrollment::query()->create($request->validated());

        return ApiResponse::created(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment->update($request->validated());

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('delete', $enrollment);

        $enrollment->delete();

        return ApiResponse::noContent();
    }

    public function cancel(CancelEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->cancel($enrollment, $request->validated('reason'), $request->user());

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function transfer(TransferEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $newClass = SchoolClass::query()->findOrFail($request->validated('class_id'));
        $newPackageId = $request->validated('course_package_id');
        $newPackage = $newPackageId ? CoursePackage::query()->findOrFail($newPackageId) : null;

        $enrollment = $this->enrollments->transferClass(
            $enrollment,
            $newClass,
            $request->user(),
            $request->validated('table_id'),
            $newPackage,
            $request->validated('fee_type'),
        );

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function changeStatus(ChangeEnrollmentStatusRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->changeStatus(
            $enrollment,
            $request->validated('status'),
            $request->validated('reason'),
            $request->validated('effective_date'),
            $request->user(),
        );

        return ApiResponse::success(new EnrollmentResource($enrollment->load(self::WITH)));
    }

    public function statusHistory(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        $history = $enrollment->statusHistories()->with('changedBy')->latest('id')->get();

        return ApiResponse::success(EnrollmentStatusHistoryResource::collection($history));
    }
}
