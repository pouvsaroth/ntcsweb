<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\WorkScheduleRequest;
use App\Http\Resources\WorkScheduleResource;
use App\Http\Responses\ApiResponse;
use App\Models\Staff;
use App\Models\WorkSchedule;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HRM > Attendance & Time > Work schedule. Only one schedule is the default
 * at a time — marking one clears the others.
 */
final class WorkScheduleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', WorkSchedule::class);

        $schedules = ApiQuery::for(WorkSchedule::query()->withCount('staff'), $request)
            ->searchable('name')
            ->sortable(['name'], default: 'name')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(WorkScheduleResource::collection($schedules));
    }

    public function store(WorkScheduleRequest $request): JsonResponse
    {
        $this->authorize('create', WorkSchedule::class);

        $schedule = DB::connection('tenant')->transaction(function () use ($request) {
            $schedule = WorkSchedule::query()->create($request->safe()->except('staff_ids'));
            $this->afterSave($schedule, $request);

            return $schedule;
        });

        return ApiResponse::created(new WorkScheduleResource($schedule->load('staff')->loadCount('staff')));
    }

    public function show(WorkSchedule $workSchedule): JsonResponse
    {
        $this->authorize('view', $workSchedule);

        return ApiResponse::success(new WorkScheduleResource($workSchedule->load('staff')->loadCount('staff')));
    }

    public function update(WorkScheduleRequest $request, WorkSchedule $workSchedule): JsonResponse
    {
        $this->authorize('update', $workSchedule);

        DB::connection('tenant')->transaction(function () use ($request, $workSchedule) {
            $workSchedule->update($request->safe()->except('staff_ids'));
            $this->afterSave($workSchedule, $request);
        });

        return ApiResponse::success(new WorkScheduleResource($workSchedule->fresh()->load('staff')->loadCount('staff')));
    }

    public function destroy(WorkSchedule $workSchedule): JsonResponse
    {
        $this->authorize('delete', $workSchedule);

        // Its staff fall back to the default schedule (FK nullOnDelete).
        $workSchedule->delete();

        return ApiResponse::noContent();
    }

    private function afterSave(WorkSchedule $schedule, WorkScheduleRequest $request): void
    {
        if ($schedule->is_default) {
            WorkSchedule::query()->whereKeyNot($schedule->id)->where('is_default', true)->update(['is_default' => false]);
        }

        if ($request->has('staff_ids')) {
            $ids = array_map('intval', $request->validated('staff_ids'));
            Staff::query()->where('work_schedule_id', $schedule->id)->whereNotIn('id', $ids)->update(['work_schedule_id' => null]);
            Staff::query()->whereIn('id', $ids)->update(['work_schedule_id' => $schedule->id]);
        }
    }
}
