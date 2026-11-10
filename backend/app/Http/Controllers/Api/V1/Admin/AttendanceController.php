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
use App\Services\Academic\AttendanceSheet;
use App\Support\Academic\SchoolDaysOff;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

final class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly AttendanceSheet $sheets,
    ) {}

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

        // `missed_date`: the previous class day with no attendance yet — while
        // set, today's attendance can't be saved (see guardPreviousClassDay()).
        // `day_off`: the holiday or School Calendar closing day on that date, if any.
        return ApiResponse::success(
            AttendanceRosterEntryResource::collection($this->attendance->roster($class, $data['date'])),
            meta: [
                'missed_date' => $this->attendance->missedClassDayBefore($class, $data['date']),
                'day_off' => SchoolDaysOff::on($data['date']),
            ],
        );
    }

    public function store(RecordAttendanceRequest $request, SchoolClass $class): JsonResponse
    {
        $this->attendance->guardPreviousClassDay($class, $request->validated('date'));

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
     * GET /classes/{class}/attendance-sheet?date_from&date_to[&status] — the
     * Attendance Sheet tab: one row per student, one mark per class day.
     * Read-only, so the same ability as the summary.
     */
    public function sheet(Request $request, SchoolClass $class): JsonResponse
    {
        return ApiResponse::success($this->buildSheet($request, $class));
    }

    public function sheetExcel(Request $request, SchoolClass $class): Response
    {
        $sheet = $this->buildSheet($request, $class);

        return $this->download($this->sheets->excel($sheet), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', AttendanceSheet::fileName($sheet, 'xlsx'));
    }

    public function sheetPdf(Request $request, SchoolClass $class): Response
    {
        $sheet = $this->buildSheet($request, $class);

        return $this->download($this->sheets->pdf($sheet), 'application/pdf', AttendanceSheet::fileName($sheet, 'pdf'));
    }

    /** @return array<string, mixed> */
    private function buildSheet(Request $request, SchoolClass $class): array
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $data = $request->validate([
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'status' => ['nullable', 'string', 'max:200'],
        ]);

        if (Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($data['date_to'])) > 366) {
            throw ValidationException::withMessages(['date_to' => 'Pick a range of one year or less.']);
        }

        return $this->sheets->build($class, $data['date_from'], $data['date_to'], $this->statusesFrom($data['status'] ?? null));
    }

    private function download(string $bytes, string $type, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }

    /**
     * `status`: omitted = Studying only, `all` = every status, or a
     * comma-separated list of enrollment statuses.
     *
     * @return list<string>|null
     */
    private function statusesFrom(?string $status): ?array
    {
        if ($status === null || $status === '') {
            return null;
        }
        if ($status === 'all') {
            return [];
        }
        $statuses = array_values(array_filter(array_map('trim', explode(',', $status))));
        if (array_diff($statuses, [...Enrollment::STATUSES_MANAGEABLE, Enrollment::STATUS_DROPPED]) !== []) {
            throw ValidationException::withMessages(['status' => 'Unknown enrollment status.']);
        }

        return $statuses;
    }

    /**
     * `status`: omitted = Studying only (the long-standing default), `all` =
     * every status, or a comma-separated list of enrollment statuses.
     */
    private function respondWithSummary(Request $request, ?int $classId): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $data = $request->validate([
            // Both optional — left blank means "all days".
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'student_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:200'],
        ]);

        $statuses = $this->statusesFrom($data['status'] ?? null);

        return ApiResponse::success($this->attendance->summarize(
            $classId,
            $data['date_from'] ?? null,
            $data['date_to'] ?? null,
            isset($data['student_id']) ? (int) $data['student_id'] : null,
            $statuses,
        ));
    }
}
