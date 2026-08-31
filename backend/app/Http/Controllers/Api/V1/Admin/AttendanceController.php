<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\RecordAttendanceRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Http\Resources\AttendanceRosterEntryResource;
use App\Http\Responses\ApiResponse;
use App\Models\AttendanceRecord;
use App\Models\SchoolClass;
use App\Services\Academic\AttendanceService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $query = AttendanceRecord::query()->with(['student', 'schoolClass']);

        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->string('date_from')->toString());
        }

        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->string('date_to')->toString());
        }

        $records = ApiQuery::for($query, $request)
            ->filterable(['class_id', 'student_id', 'status'])
            ->sortable(['date', 'created_at'], default: '-date')
            ->paginate();

        return ApiResponse::success(AttendanceRecordResource::collection($records));
    }

    public function show(AttendanceRecord $attendance): JsonResponse
    {
        $this->authorize('view', $attendance);

        return ApiResponse::success(new AttendanceRecordResource($attendance->load(['student', 'schoolClass', 'recordedBy'])));
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
}
