<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RecordExamApplicationFeeRequest;
use App\Http\Requests\Api\V1\Admin\RejectExamApplicationRequest;
use App\Http\Requests\Api\V1\Admin\StoreExamApplicationRequest;
use App\Http\Requests\Api\V1\Admin\UpdateExamApplicationRequest;
use App\Http\Resources\ExamApplicationResource;
use App\Http\Responses\ApiResponse;
use App\Models\ExamApplication;
use App\Models\Tenant;
use App\Services\Academic\ExamApplicationService;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExamApplicationController extends Controller
{
    private const WITH = [
        'student.village.commune.district.province', 'enrollment.coursePackage', 'enrollment.schoolClass',
        'decidedBy', 'book', 'classroom', 'table',
    ];

    public function __construct(
        private readonly ExamApplicationService $examApplications,
        private readonly TenantContext $context,
    ) {}

    /**
     * `student_submitted=1` scopes to applications a student filed
     * themselves (MyExamApplicationController::store() always stamps
     * `student_marked_paid_at`; an admin-created one never does) — the "Exam
     * Application Approval" tab's data source.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExamApplication::class);

        $query = ExamApplication::query()->with(self::WITH);

        if ($request->boolean('student_submitted')) {
            $query->whereNotNull('student_marked_paid_at');
        }

        $applications = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id', 'enrollment_id'])
            ->sortable(['exam_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ExamApplicationResource::collection($applications));
    }

    public function show(ExamApplication $examApplication): JsonResponse
    {
        $this->authorize('view', $examApplication);

        return ApiResponse::success(new ExamApplicationResource($examApplication->load(self::WITH)));
    }

    /**
     * GET /exam-applications/lookup?enrollment_code=NTS-000008-01 — the
     * Application Form's "Show Data" button. `enrollment_code` is an
     * Enrollment's own `enrollments_code`, see
     * ExamApplicationService::lookupByEnrollmentCode().
     */
    public function lookup(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ExamApplication::class);

        $data = $request->validate(['enrollment_code' => ['required', 'string']]);

        $enrollment = $this->examApplications->lookupByEnrollmentCode($data['enrollment_code']);
        $enrollment->loadMissing('student.village.commune.district.province');

        $existing = $enrollment->getRelation('latestExamApplication');

        return ApiResponse::success([
            'enrollment_id' => $enrollment->id,
            'enrollment_code' => $enrollment->enrollments_code,
            'student' => [
                'id' => $enrollment->student->id,
                'student_code' => $enrollment->student->student_code,
                'name' => $enrollment->student->fullName(),
                'first_name' => $enrollment->student->first_name,
                'last_name' => $enrollment->student->last_name,
                'english_name' => $enrollment->student->english_name,
                'gender' => $enrollment->student->gender,
                'date_of_birth' => $enrollment->student->date_of_birth?->toDateString(),
                'phone' => $enrollment->student->phone,
                'village_code' => $enrollment->student->village_code,
                'address' => $enrollment->student->fullAddress(),
                'address_parts' => $enrollment->student->addressParts(),
                'photo_url' => $enrollment->student->photoUrl(),
            ],
            'course_package' => $enrollment->coursePackage !== null
                ? ['id' => $enrollment->coursePackage->id, 'name' => $enrollment->coursePackage->name]
                : null,
            'exam_application' => $existing !== null ? new ExamApplicationResource($existing) : null,
        ]);
    }

    public function store(StoreExamApplicationRequest $request): JsonResponse
    {
        $application = $this->examApplications->createForAdmin($request->validated());

        return ApiResponse::created(new ExamApplicationResource($application->load(self::WITH)));
    }

    public function update(UpdateExamApplicationRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $application = $this->examApplications->updateForAdmin($examApplication, $request->validated());

        return ApiResponse::success(new ExamApplicationResource($application->load(self::WITH)));
    }

    public function destroy(ExamApplication $examApplication): JsonResponse
    {
        $this->authorize('delete', $examApplication);

        $examApplication->delete();

        return ApiResponse::success(null);
    }

    /**
     * POST /exam-applications/{exam_application}/print — "Print": takes the
     * exam fee at the counter, records it as a real Invoice + Payment, and
     * stamps `sold_at`. One application at a time (not bulk like
     * Receive/Pay Back below) — printing the Application Form is inherently
     * a single-student document.
     */
    public function print(RecordExamApplicationFeeRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $tenant = $this->context->getOrFail();

        $application = $this->examApplications->sellAndRecordFee(
            $examApplication,
            (float) $request->validated('fee'),
            $request->validated('currency') ?? $tenant->default_currency ?? Tenant::CURRENCY_USD,
            $request->validated('payment_method'),
            $request->validated('print_date'),
            $request->user(),
        );

        return ApiResponse::success(new ExamApplicationResource($application->load(self::WITH)));
    }

    /** POST /exam-applications/receive — "RECEIVE WORD": stamps received_at on every selected row. */
    public function receive(Request $request): JsonResponse
    {
        $ids = $this->authorizedBulkIds($request);

        $applications = $this->examApplications->markReceived($ids);

        return ApiResponse::success(ExamApplicationResource::collection($applications->load(self::WITH)));
    }

    /** POST /exam-applications/pay-back — "PAY BACK EXAM": stamps paid_back_at on every selected row. */
    public function payBack(Request $request): JsonResponse
    {
        $ids = $this->authorizedBulkIds($request);

        $applications = $this->examApplications->markPaidBack($ids);

        return ApiResponse::success(ExamApplicationResource::collection($applications->load(self::WITH)));
    }

    public function approve(ExamApplication $examApplication, Request $request): JsonResponse
    {
        $this->authorize('approve', $examApplication);

        $examApplication = $this->examApplications->approve($examApplication, $request->user());

        return ApiResponse::success(new ExamApplicationResource($examApplication->load(self::WITH)));
    }

    public function reject(RejectExamApplicationRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $examApplication = $this->examApplications->reject($examApplication, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ExamApplicationResource($examApplication->load(self::WITH)));
    }

    /**
     * Shared by sell/receive/payBack: validates the id list, then checks the
     * `update` ability against every row individually (not just once) —
     * authorizing on the first row and silently trusting the rest would let
     * a caller slip in an id they don't actually have access to.
     *
     * @return list<int>
     */
    private function authorizedBulkIds(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:tenant.exam_applications,id'],
        ]);

        $applications = ExamApplication::query()->whereIn('id', $data['ids'])->get();

        foreach ($applications as $application) {
            $this->authorize('update', $application);
        }

        return $data['ids'];
    }
}
