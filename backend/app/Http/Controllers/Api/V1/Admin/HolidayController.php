<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\HolidayRequest;
use App\Http\Resources\HolidayResource;
use App\Http\Responses\ApiResponse;
use App\Models\Holiday;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** HRM > Attendance & Time > Holidays. */
final class HolidayController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Holiday::class);

        $query = Holiday::query();

        if ($request->filled('year')) {
            $year = $request->integer('year');
            $query->whereDate('end_date', '>=', "{$year}-01-01")->whereDate('start_date', '<=', "{$year}-12-31");
        }

        $holidays = ApiQuery::for($query, $request)
            ->searchable('name')
            ->sortable(['start_date'], default: 'start_date')
            ->maxPerPage(200)
            ->paginate();

        return ApiResponse::success(HolidayResource::collection($holidays));
    }

    public function store(HolidayRequest $request): JsonResponse
    {
        $this->authorize('create', Holiday::class);

        return ApiResponse::created(new HolidayResource(Holiday::query()->create($request->validated())));
    }

    public function show(Holiday $holiday): JsonResponse
    {
        $this->authorize('view', $holiday);

        return ApiResponse::success(new HolidayResource($holiday));
    }

    public function update(HolidayRequest $request, Holiday $holiday): JsonResponse
    {
        $this->authorize('update', $holiday);

        $holiday->update($request->validated());

        return ApiResponse::success(new HolidayResource($holiday));
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->authorize('delete', $holiday);

        $holiday->delete();

        return ApiResponse::noContent();
    }
}
