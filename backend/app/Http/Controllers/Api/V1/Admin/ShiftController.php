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

/** HRM > Attendance & Time > Shift. */
final class ShiftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Shift::class);

        $shifts = ApiQuery::for(Shift::query(), $request)
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

        return ApiResponse::created(new ShiftResource(Shift::query()->create($request->validated())));
    }

    public function show(Shift $shift): JsonResponse
    {
        $this->authorize('view', $shift);

        return ApiResponse::success(new ShiftResource($shift));
    }

    public function update(ShiftRequest $request, Shift $shift): JsonResponse
    {
        $this->authorize('update', $shift);

        $shift->update($request->validated());

        return ApiResponse::success(new ShiftResource($shift));
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
