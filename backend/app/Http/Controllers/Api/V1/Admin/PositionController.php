<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StorePositionRequest;
use App\Http\Requests\Api\V1\Admin\UpdatePositionRequest;
use App\Http\Resources\PositionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Position;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PositionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Position::class);

        $positions = ApiQuery::for(Position::query()->with('role')->withCount('staff'), $request)
            ->searchable('name')
            ->filterable(['status'])
            ->sortable(['name', 'created_at'], default: 'name')
            ->paginate();

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

        return ApiResponse::success(new PositionResource($position->loadCount('staff')->load('role')));
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
}
