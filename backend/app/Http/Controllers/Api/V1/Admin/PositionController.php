<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePositionRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Position;
use App\Models\Staff;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Position::class);

        $positions = ApiQuery::for(Position::query()->with('role'), $request)
            ->searchable('name')
            ->filterable(['status'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

        $this->attachStaffCounts($positions);

        return ApiResponse::success(PositionResource::collection($positions));
    }

    public function store(StorePositionRequest $request): JsonResponse
    {
        $position = Position::query()->create($request->validated());

        return ApiResponse::created(new PositionResource($position->load('role')));
    }

    public function show(Position $position): JsonResponse
    {
        $this->authorize('view', $position);

        $position->load('role');
        $this->attachStaffCounts([$position]);

        return ApiResponse::success(new PositionResource($position));
    }

    public function update(UpdatePositionRequest $request, Position $position): JsonResponse
    {
        $position->update($request->validated());

        return ApiResponse::success(new PositionResource($position->load('role')));
    }

    public function destroy(Position $position): JsonResponse
    {
        $this->authorize('delete', $position);

        $position->delete();

        return ApiResponse::noContent();
    }

    /**
     * `staff` and `positions` both live in the tenant database now, but this
     * predates that move (`positions` was still central when `staff_count`
     * first had to stop coming from withCount()/loadCount() — a single
     * cross-database subquery at the time) and remains correct as a
     * same-connection query — resolved separately and attached manually, the
     * shape PositionResource expects via whenCounted().
     *
     * @param  iterable<Position>  $positions
     */
    private function attachStaffCounts(iterable $positions): void
    {
        // Not collect($positions)->all(): a LengthAwarePaginator implements
        // Arrayable, so collect() would call its toArray() — the pagination
        // metadata shape, not the underlying models.
        $models = $positions instanceof \Illuminate\Contracts\Pagination\Paginator ? $positions->items() : (is_array($positions) ? $positions : iterator_to_array($positions));

        if ($models === []) {
            return;
        }

        $counts = Staff::query()
            ->whereIn('position_id', collect($models)->pluck('id'))
            ->select('position_id', DB::raw('COUNT(*) as total'))
            ->groupBy('position_id')
            ->pluck('total', 'position_id');

        foreach ($models as $position) {
            $position->setAttribute('staff_count', (int) ($counts[$position->id] ?? 0));
        }
    }
}
