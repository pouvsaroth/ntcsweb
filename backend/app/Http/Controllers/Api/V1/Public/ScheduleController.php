<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\ClassSchedule;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;

/**
 * "Day and Time Study" — the public schedule of currently-running classes.
 * Unauthenticated, gated only by `tenant.required` on the route group, same
 * as every other public endpoint.
 *
 * `day_of_week` travels as the raw ISO int (1 = Monday ... 7 = Sunday), not
 * `ClassSchedule::dayName()`'s English string — the site supports 5
 * languages, so the frontend localises the day name itself, the same way it
 * already does for Program::$level (see Programs.vue's `levelLabel` map).
 */
final class ScheduleController extends Controller
{
    public function index(): JsonResponse
    {
        $classes = SchoolClass::query()->active()
            ->with(['teacher', 'schedules' => fn ($query) => $query->orderBy('day_of_week')])
            ->orderBy('name')
            ->get();

        return ApiResponse::success($classes->map(fn (SchoolClass $class) => [
            'id' => $class->id,
            'name' => $class->name,
            'teacher_name' => $class->teacher?->fullName(),
            'schedules' => $class->schedules->map(fn (ClassSchedule $schedule) => [
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ]),
        ]));
    }
}
