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
use App\Services\Approvals\ApprovalFlow;
use App\Support\Approvals\DocumentType;
use App\Support\Query\ApiQuery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
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
        private readonly ApprovalFlow $flow,
    ) {}

    /**
     * `student_submitted=1` scopes to applications a student filed
     * themselves (MyExamApplicationController::store() always stamps
     * `student_marked_paid_at`; an admin-created one never does) — the "Exam
     * Application Approval" tab's data source.
     */
    public function index(Request $request): JsonResponse
    {
        // An approval-flow group member may open the queue without the
        // view permission — they then only see the requests involving them.
        $user = $request->user();
        $canViewAll = $user->can('viewAny', ExamApplication::class);
        abort_unless($canViewAll || $this->flow->isApproverFor($user, DocumentType::forModelClass(ExamApplication::class)), 403);

        $query = ExamApplication::query()->with(self::WITH);

        if ($request->boolean('student_submitted')) {
            $query->whereNotNull('student_marked_paid_at');
        }

        // The Exams tab's default view (see Exams.vue's "Awaiting action"
        // dropdown option, selected on first load): draft/pending
        // applications still need a decision, and an approved or make-up
        // one still needs a score recorded (see the Grades tab /
        // ExamScoreController — a make-up is scoreable immediately, no
        // separate approval step). Once scored, or once rejected/marked
        // not_exam, a row has nothing left this tab can act on, so it drops
        // out of this default list — a plain top-level param, not
        // `filter[status]`, since "approved/make-up but unscored" isn't a
        // single column value ApiQuery's generic filterable() could express.
        if ($request->boolean('awaiting_action')) {
            $query->where(function (Builder $inner) {
                $inner->whereIn('status', [ExamApplication::STATUS_DRAFT, ExamApplication::STATUS_PENDING])
                    ->orWhere(function (Builder $scoreable) {
                        $scoreable->scoreable()->doesntHave('score');
                    });
            });
        }

        // The Approvals queue lists a pending request of an item with an
        // approval flow only to the group it's waiting on (see ApprovalFlow).
        if ($request->boolean('approval_queue') || ! $canViewAll) {
            $this->flow->scopeQueue($query, ExamApplication::class, $user, $canViewAll);
        }

        // Examination → Certificate: passed students only, with their score.
        // `photo_received` = yes / no narrows to whether their certificate
        // photo has been handed in yet.
        if ($request->boolean('certificate')) {
            $query->passed()->with('score');

            match ($request->query('photo_received')) {
                'yes' => $query->whereNotNull('photo_received_date'),
                'no' => $query->whereNull('photo_received_date'),
                default => null,
            };
        }

        $applications = ApiQuery::for($query, $request)
            ->filterable(['status', 'student_id', 'enrollment_id'])
            ->sortable(['exam_date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->flow->attachProgress($applications->getCollection(), $user);

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
        $application = $this->examApplications->createForAdmin($request->validated(), $request->user());

        return ApiResponse::created(new ExamApplicationResource($application->load(self::WITH)));
    }

    public function update(UpdateExamApplicationRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $application = $this->examApplications->updateForAdmin($examApplication, $request->validated(), $request->user());

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

    /**
     * POST /exam-applications/photo-received — Examination → Certificate's
     * "Received photo": records the date (and an optional remark) each
     * selected passed student handed in their certificate photo.
     */
    public function photoReceived(Request $request): JsonResponse
    {
        $ids = $this->authorizedBulkIds($request);
        $data = $request->validate([
            'received_date' => ['required', 'date', 'before_or_equal:today'],
            'remark' => ['nullable', 'string', 'max:500'],
        ]);

        $applications = $this->examApplications->markPhotoReceived($ids, $data['received_date'], $data['remark'] ?? null, $request->user());

        return ApiResponse::success(ExamApplicationResource::collection($applications->load([...self::WITH, 'score'])));
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
        $this->flow->authorizeDecision($examApplication, $request->user(), 'approve');

        // With an approval flow this approves just the current step (and
        // tells the next step's group); the last step approves the request.
        $examApplication = $this->flow->approve(
            $examApplication,
            $request->user(),
            fn ($doc) => $this->examApplications->approve($doc, $request->user()),
            fn ($doc, $nextApprovers) => $this->examApplications->notifyApprovers($doc, $nextApprovers),
        );

        return ApiResponse::success(new ExamApplicationResource($examApplication->load(self::WITH)));
    }

    public function reject(RejectExamApplicationRequest $request, ExamApplication $examApplication): JsonResponse
    {
        $examApplication = $this->examApplications->reject($examApplication, $request->validated('reason'), $request->user());

        return ApiResponse::success(new ExamApplicationResource($examApplication->load(self::WITH)));
    }

    /**
     * "Not Exam" — a per-row action in the Examination tab for a student
     * who was sent to exam (still draft) but doesn't want to sit it. Gated
     * by the same `update` ability as Receive/Pay Back/Print, not a
     * dedicated permission.
     */
    public function markNotExam(ExamApplication $examApplication): JsonResponse
    {
        $this->authorize('update', $examApplication);

        $examApplication = $this->examApplications->markNotExam($examApplication);

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
