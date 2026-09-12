<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreSchoolClassRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SchoolClassController extends Controller
{
    private const WITH = ['teachers', 'assistantTeachers', 'classroom', 'schedules', 'academicProgram'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $query = SchoolClass::query()->with(self::WITH)->withCount(['enrollments as active_students_count' => fn ($q) => $q->active()]);

        // Not a plain column, so it can't go through ApiQuery's generic
        // filterable() — read straight out of the same filter[] bucket the
        // frontend already sends everything else through.
        if ($request->boolean('filter.has_active_enrollment')) {
            $query->whereHas('enrollments', fn ($q) => $q->active());
        }

        $classes = ApiQuery::for($query, $request)
            ->searchable('name', 'code')
            ->filterable(['status', 'classroom_id', 'academic_program_id'])
            ->sortable(['name', 'start_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(SchoolClassResource::collection($classes));
    }

    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $class = DB::connection('tenant')->transaction(function () use ($request) {
            $class = SchoolClass::query()->create($request->safe()->except(['schedules', 'teacher_ids', 'assistant_teacher_ids']));

            $this->syncSchedules($class, $request->validated('schedules', []));
            $class->teachers()->sync($request->validated('teacher_ids', []));
            $class->assistantTeachers()->sync($request->validated('assistant_teacher_ids', []));

            return $class;
        });

        return ApiResponse::created(new SchoolClassResource($class->load(self::WITH)));
    }

    public function show(SchoolClass $class): JsonResponse
    {
        $this->authorize('view', $class);

        return ApiResponse::success(new SchoolClassResource($class->load(self::WITH)->loadCount(['enrollments as active_students_count' => fn ($q) => $q->active()])));
    }

    public function update(UpdateSchoolClassRequest $request, SchoolClass $class): JsonResponse
    {
        DB::connection('tenant')->transaction(function () use ($request, $class) {
            $class->update($request->safe()->except(['schedules', 'teacher_ids', 'assistant_teacher_ids']));

            if ($request->has('schedules')) {
                $this->syncSchedules($class, $request->validated('schedules'));
            }

            if ($request->has('teacher_ids')) {
                $class->teachers()->sync($request->validated('teacher_ids'));
            }

            if ($request->has('assistant_teacher_ids')) {
                $class->assistantTeachers()->sync($request->validated('assistant_teacher_ids'));
            }
        });

        return ApiResponse::success(new SchoolClassResource($class->load(self::WITH)));
    }

    public function destroy(SchoolClass $class): JsonResponse
    {
        $this->authorize('delete', $class);

        $class->delete();

        return ApiResponse::noContent();
    }

    /**
     * Which tables in this class's classroom are still free — drives the
     * enrollment form's table picker. `total_tables` lets the frontend tell
     * "this room has no tables configured" (0, field not required) apart
     * from "this room is full" (>0 but `available` is empty).
     */
    public function availableTables(SchoolClass $class): JsonResponse
    {
        $this->authorize('create', Enrollment::class);

        if ($class->classroom_id === null) {
            return ApiResponse::success(['total_tables' => 0, 'available' => []]);
        }

        $totalTables = ClassroomTable::query()->where('classroom_id', $class->classroom_id)->count();

        // A plain separate query rather than a whereDoesntHave() subquery —
        // simpler to read for a one-off computation like this, not forced by
        // any connection boundary (classroom_tables/enrollments/classes all
        // live in the same tenant database).
        $takenTableIds = Enrollment::query()
            ->where('class_id', $class->id)
            ->where('status', '!=', Enrollment::STATUS_DROPPED)
            ->whereNotNull('table_id')
            ->pluck('table_id');

        $available = ClassroomTable::query()
            ->where('classroom_id', $class->classroom_id)
            ->whereNotIn('id', $takenTableIds)
            ->orderBy('name')
            ->get(['id', 'name']);

        return ApiResponse::success(['total_tables' => $totalTables, 'available' => $available]);
    }

    /**
     * Replaces the class's entire weekly schedule. Delete-and-recreate rather
     * than diffing: a class's schedule is a handful of rows at most, and this
     * sidesteps every edge case a partial update against day_of_week +
     * start_time would otherwise need to handle.
     *
     * @param  list<array{day_of_week: int, start_time: string, end_time: string}>  $schedules
     */
    private function syncSchedules(SchoolClass $class, array $schedules): void
    {
        $class->schedules()->delete();

        if ($schedules === []) {
            return;
        }

        $now = now();

        $class->schedules()->insert(array_map(
            fn (array $schedule) => [
                'class_id' => $class->id,
                'day_of_week' => $schedule['day_of_week'],
                'start_time' => $schedule['start_time'],
                'end_time' => $schedule['end_time'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $schedules,
        ));
    }
}
