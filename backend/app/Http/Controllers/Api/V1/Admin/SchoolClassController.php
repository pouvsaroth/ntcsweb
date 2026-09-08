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
    private const WITH = ['teacher', 'classroom', 'schedules', 'books', 'academicProgram', 'coursePackages'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $classes = ApiQuery::for(
            SchoolClass::query()->with(self::WITH),
            $request,
        )
            ->searchable('name', 'code')
            ->filterable(['status', 'teacher_id', 'classroom_id', 'academic_program_id'])
            ->sortable(['name', 'start_date', 'created_at'], default: '-created_at')
            ->paginate();

        $this->attachEnrollmentCounts($classes);

        return ApiResponse::success(SchoolClassResource::collection($classes));
    }

    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $class = DB::transaction(function () use ($request) {
            $class = SchoolClass::query()->create($request->safe()->except(['schedules', 'book_ids', 'course_package_ids']));

            $this->syncSchedules($class, $request->validated('schedules', []));
            $class->books()->sync($request->validated('book_ids', []));
            $class->coursePackages()->sync($request->validated('course_package_ids', []));

            return $class;
        });

        return ApiResponse::created(new SchoolClassResource($class->load(self::WITH)));
    }

    public function show(SchoolClass $class): JsonResponse
    {
        $this->authorize('view', $class);

        $class->load(self::WITH);
        $this->attachEnrollmentCounts([$class]);

        return ApiResponse::success(new SchoolClassResource($class));
    }

    public function update(UpdateSchoolClassRequest $request, SchoolClass $class): JsonResponse
    {
        DB::transaction(function () use ($request, $class) {
            $class->update($request->safe()->except(['schedules', 'book_ids', 'course_package_ids']));

            if ($request->has('schedules')) {
                $this->syncSchedules($class, $request->validated('schedules'));
            }

            if ($request->has('book_ids')) {
                $class->books()->sync($request->validated('book_ids'));
            }

            if ($request->has('course_package_ids')) {
                $class->coursePackages()->sync($request->validated('course_package_ids'));
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

        // `enrollments` lives in the tenant database, `classroom_tables` in
        // the central one, so "which tables are taken" is resolved as a
        // separate query rather than a whereDoesntHave() subquery — the same
        // reasoning as attachEnrollmentCounts() below.
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
     * `enrollments` lives in the tenant database while `classes` is still
     * central, so `enrollments_count` can no longer come from
     * withCount()/loadCount() (a single cross-database subquery) — it's
     * resolved as a separate tenant-connection query and attached manually,
     * the same shape SchoolClassResource/StudentResource already expect via
     * whenCounted().
     *
     * @param  iterable<SchoolClass>  $classes
     */
    private function attachEnrollmentCounts(iterable $classes): void
    {
        // Not collect($classes)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $classes instanceof \Illuminate\Contracts\Pagination\Paginator ? $classes->items() : (is_array($classes) ? $classes : iterator_to_array($classes));

        if ($models === []) {
            return;
        }

        $counts = Enrollment::query()
            ->whereIn('class_id', collect($models)->pluck('id'))
            ->select('class_id', DB::raw('COUNT(*) as total'))
            ->groupBy('class_id')
            ->pluck('total', 'class_id');

        foreach ($models as $class) {
            $class->setAttribute('enrollments_count', (int) ($counts[$class->id] ?? 0));
        }
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
