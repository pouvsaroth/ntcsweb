<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreMyMakeUpClassRequestRequest;
use App\Http\Resources\MakeUpClassRequestResource;
use App\Http\Responses\ApiResponse;
use App\Models\MakeUpClassRequest;
use App\Models\Student;
use App\Services\Academic\MakeUpClassRequestService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Self-service: "my make-up class requests," not "all make-up class
 * requests." Identity-gated through `$user->student` — no permission is
 * required or checked here, same pattern as MyLeaveRequestController.
 */
final class MyMakeUpClassRequestController extends Controller
{
    public function __construct(
        private readonly MakeUpClassRequestService $makeUpClassRequests,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $query = MakeUpClassRequest::query()
            ->where('student_id', $student->id)
            ->with(['enrollment.coursePackage', 'enrollment.schoolClass']);

        $requests = ApiQuery::for($query, $request)
            ->filterable(['status'])
            ->sortable(['from_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(MakeUpClassRequestResource::collection($requests));
    }

    public function store(StoreMyMakeUpClassRequestRequest $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $makeUpClassRequest = $this->makeUpClassRequests->submit($student, $request->validated());

        return ApiResponse::created(new MakeUpClassRequestResource($makeUpClassRequest->load(['student', 'enrollment.coursePackage', 'enrollment.schoolClass'])));
    }

    /**
     * The requester withdraws their own request while it is still waiting
     * (pending) — once any decision is made it can no longer be deleted.
     * Someone else's request 404s, same as if it didn't exist.
     */
    public function destroy(Request $request, int $makeUpClassRequest): JsonResponse
    {
        $student = $this->studentOrFail($request);

        DB::transaction(function () use ($student, $makeUpClassRequest) {
            $row = MakeUpClassRequest::query()
                ->where('student_id', $student->id)
                ->whereKey($makeUpClassRequest)
                ->lockForUpdate()
                ->firstOrFail();

            if ($row->status !== MakeUpClassRequest::STATUS_PENDING) {
                throw ValidationException::withMessages(['status' => 'Only a request that is still waiting can be deleted.']);
            }

            $row->delete();
        });

        return ApiResponse::noContent();
    }

    /**
     * The student's active enrollments for the form's Course picker, newest
     * first — the frontend pre-selects the first one as the "current" course.
     */
    public function enrollments(Request $request): JsonResponse
    {
        $student = $this->studentOrFail($request);

        $enrollments = $student->enrollments()
            ->active()
            ->with('coursePackage', 'schoolClass')
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
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
