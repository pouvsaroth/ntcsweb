<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\ShiftRequest;
use App\Http\Resources\ShiftResource;
use App\Http\Responses\ApiResponse;
use App\Models\Shift;
use App\Models\WorkSchedule;
use App\Support\Query\ApiQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** HRM > Attendance & Time > Shift. */
final class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $shifts = ApiQuery::for(Shift::query()->with('days'), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['start_time', 'code', 'name'], default: 'start_time')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(ShiftResource::collection($shifts));
    }

    public function store(ShiftRequest $request): JsonResponse
    {
        $this->authorize('create', Shift::class);

        $shift = DB::connection('tenant')->transaction(fn () => $this->fill(new Shift, $request->validated()));

        return ApiResponse::created(new ShiftResource($shift->load('days')));
    }

    public function show(Shift $shift): JsonResponse
    {
        $this->authorize('view', $shift);

        return ApiResponse::success(new ShiftResource($shift->load('days')));
    }

    public function update(ShiftRequest $request, Shift $shift): JsonResponse
    {
        $this->authorize('update', $shift);

        DB::connection('tenant')->transaction(fn () => $this->fill($shift, $request->validated()));

        return ApiResponse::success(new ShiftResource($shift->load('days')));
    }

    /**
     * Saves the shift and, when `days` was sent, replaces its Day / From / To
     * rows — the first day's hours also become the shift's own start/end,
     * its fallback for any other weekday (see Shift::timesOn()).
     *
     * @param  array<string, mixed>  $data
     */
    private function fill(Shift $shift, array $data): Shift
    {
        $days = isset($data['days']) ? collect($data['days'])->sortBy('day_of_week')->values() : null;
        unset($data['days']);

        if ($days !== null) {
            $data['start_time'] = $days->first()['start_time'];
            $data['end_time'] = $days->first()['end_time'];
            if (isset($days->first()['break_minutes'])) {
                $data['break_minutes'] = (int) $days->first()['break_minutes'];
            }
        }

        $shift->fill($data)->save();

        if ($days !== null) {
            $shift->days()->delete();
            $shift->days()->createMany($days->map(fn (array $day) => [
                'day_of_week' => (int) $day['day_of_week'],
                'start_time' => $day['start_time'],
                'end_time' => $day['end_time'],
                'break_minutes' => isset($day['break_minutes']) ? (int) $day['break_minutes'] : null,
            ])->all());
        }

        return $shift;
    }

    public function destroy(Shift $shift): JsonResponse
    {
        $this->authorize('delete', $shift);

        $inUse = WorkSchedule::query()->where(function (Builder $query) use ($shift) {
            foreach (WorkSchedule::DAYS as $day) {
                $query->orWhere("{$day}_shift_id", $shift->id);
            }
        })->exists();

        if ($inUse) {
            return ApiResponse::error('This shift is used in a work schedule and cannot be deleted. Deactivate it instead.', 422);
        }

        $shift->delete();

        return ApiResponse::noContent();
    }
}
