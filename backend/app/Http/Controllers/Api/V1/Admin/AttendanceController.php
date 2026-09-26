<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RecordAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceRosterEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Services\Academic\AttendanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

final class AttendanceController extends Controller
{
    public function __construct(private readonly AttendanceService $attendance) {}

    /** Full history/review list — filterable by class, student, status, and date range. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = AttendanceRecord::query()->with(['student', 'schoolClass.schedules']);

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->string('date_to')->toString());
        }

        $records = ApiQuery::for($query, $request)
            ->filterable(['class_id', 'student_id', 'status', 'enrollment_id'])
            ->sortable(['date', 'created_at'], default: '-date')
            ->paginate();

        return ApiResponse::success(AttendanceRecordResource::collection($records));
    }

    public function show(AttendanceRecord $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return ApiResponse::success(new AttendanceRecordResource($attendance->load(['student', 'schoolClass.schedules', 'recordedBy'])));
    }

    /** GET /classes/{class}/attendance?date=YYYY-MM-DD — the "take attendance" screen's data source. */
    public function roster(Request $request, SchoolClass $class): JsonResponse
    {
        $this->authorize('recordAttendance', $class);

        $data = $request->validate(['date' => ['required', 'date']]);

        return ApiResponse::success(
            AttendanceRosterEntryResource::collection($this->attendance->roster($class, $data['date']))
        );
    }

    public function store(RecordAttendanceRequest $request, SchoolClass $class): JsonResponse
    {
        $records = $this->attendance->recordForClass(
            $class,
            $request->validated('date'),
            $request->validated('entries'),
            $request->user(),
        );

        return ApiResponse::success(AttendanceRecordResource::collection($records->load(['student'])));
    }

    /**
     * GET /classes/{class}/attendance-summary — per-student present/permission/
     * absent day+hour totals (plus total late minutes) for the class over a
     * date range. Read-only, so it authorizes off the same ability as the
     * history list rather than `recordAttendance` — a viewer who can't take
     * attendance can still see the summary.
     */
    public function summary(Request $request, SchoolClass $class): JsonResponse
    {
        return $this->respondWithSummary($request, $class->getKey());
    }

    /**
     * GET /attendance-summary — the Attendance Summary tab's data source: the
     * same as summary() above but with an optional `class_id` (absent = every
     * class). The same viewAny ability already lets its holder list every
     * class's records through index(), so this exposes nothing new.
     */
    public function summaryAcrossClasses(Request $request): JsonResponse
    {
        $request->validate(['class_id' => ['nullable', 'integer']]);

        return $this->respondWithSummary($request, $request->filled('class_id') ? $request->integer('class_id') : null);
    }

    /**
     * `status`: omitted = Studying only (the long-standing default), `all` =
     * every status, or a comma-separated list of enrollment statuses.
     */
    private function respondWithSummary(Request $request, ?int $classId): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'student_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:200'],
        ]);

        $statuses = null;
        if (($data['status'] ?? '') === 'all') {
            $statuses = [];
        } elseif (! empty($data['status'])) {
            $statuses = array_values(array_filter(array_map('trim', explode(',', $data['status']))));
            $valid = [...Enrollment::STATUSES_MANAGEABLE, Enrollment::STATUS_DROPPED];

            if (array_diff($statuses, $valid) !== []) {
                throw ValidationException::withMessages(['status' => 'Unknown enrollment status.']);
            }
        }

        return ApiResponse::success($this->attendance->summarize(
            $classId,
            $data['date_from'],
            $data['date_to'],
            isset($data['student_id']) ? (int) $data['student_id'] : null,
            $statuses,
        ));
    }
}
