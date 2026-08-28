<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreSchoolClassRequest;
use App\Http\Requests\Api\V1\Admin\UpdateSchoolClassRequest;
use App\Http\Resources\SchoolClassResource;
use App\Http\Responses\ApiResponse;
use App\Models\SchoolClass;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SchoolClassController extends Controller
{
    private const WITH = ['teacher', 'classroom', 'schedules', 'books'];

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $classes = ApiQuery::for(
            SchoolClass::query()->with(['teacher', 'classroom'])->withCount('enrollments'),
            $request,
        )
            ->searchable('name', 'code')
            ->filterable(['status', 'teacher_id', 'classroom_id'])
            ->sortable(['name', 'start_date', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(SchoolClassResource::collection($classes));
    }

    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $class = DB::transaction(function () use ($request) {
            $class = SchoolClass::query()->create($request->safe()->except(['schedules', 'book_ids']));

            $this->syncSchedules($class, $request->validated('schedules', []));
            $class->books()->sync($request->validated('book_ids', []));

            return $class;
        });

        return ApiResponse::created(new SchoolClassResource($class->load(self::WITH)));
    }

    public function show(SchoolClass $class): JsonResponse
    {
        $this->authorize('view', $class);

        return ApiResponse::success(
            new SchoolClassResource($class->load(self::WITH)->loadCount('enrollments'))
        );
    }

    public function update(UpdateSchoolClassRequest $request, SchoolClass $class): JsonResponse
    {
        DB::transaction(function () use ($request, $class) {
            $class->update($request->safe()->except(['schedules', 'book_ids']));

            if ($request->has('schedules')) {
                $this->syncSchedules($class, $request->validated('schedules'));
            }

            if ($request->has('book_ids')) {
                $class->books()->sync($request->validated('book_ids'));
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
                'tenant_id' => $class->tenant_id,
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
