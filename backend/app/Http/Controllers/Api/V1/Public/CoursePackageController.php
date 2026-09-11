<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicCoursePackageResource;
use App\Http\Responses\ApiResponse;
use App\Models\ClassSchedule;
use App\Models\CoursePackage;
use App\Models\SchoolClass;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The public course catalog. Unauthenticated — gated only by
 * `tenant.required` on the route group, same as every other public
 * endpoint (see Public\ProgramController, whose `?featured=1` convention
 * this mirrors). By default only active packages flagged
 * `show_on_website` are returned (the full /programs page); `?featured=1`
 * instead returns those flagged `show_in_popular` (the homepage's
 * "Popular Programs" section) — the two flags are independent, so a
 * package can appear on either, both, or neither. The frontend groups the
 * flat list by `academic_program` itself.
 */
final class CoursePackageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = CoursePackage::query()->active()->with('academicProgram')->orderBy('name');

        $query->where($request->boolean('featured') ? 'show_in_popular' : 'show_on_website', true);

        return ApiResponse::success(PublicCoursePackageResource::collection($query->get()));
    }

    /**
     * Which currently-running classes this package can be enrolled into,
     * with their weekly schedule — the registration wizard's "Schedule"
     * step. Same shape as Public\ScheduleController's own listing. Matched
     * by program, exactly like EnrollmentService::assertEnrollable() does
     * for staff-created enrollments — a class is just a schedule/room/
     * teacher, never gated to a specific package beyond sharing its program.
     */
    public function classes(CoursePackage $coursePackage): JsonResponse
    {
        $classes = SchoolClass::query()->active()
            ->where('academic_program_id', $coursePackage->academic_program_id)
            ->with(['teachers', 'schedules' => fn ($query) => $query->orderBy('day_of_week')])
            ->orderBy('name')
            ->get();

        return ApiResponse::success($classes->map(fn (SchoolClass $class) => [
            'id' => $class->id,
            'name' => $class->name,
            'teacher_name' => $class->teachers->isEmpty() ? null : $class->teachers->map->fullName()->implode(', '),
            'schedules' => $class->schedules->map(fn (ClassSchedule $schedule) => [
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ]),
        ]));
    }
}
