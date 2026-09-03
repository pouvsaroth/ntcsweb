<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreStudyModeRequest;
use App\Http\Requests\Api\V1\Admin\UpdateStudyModeRequest;
use App\Http\Resources\StudyModeResource;
use App\Http\Responses\ApiResponse;
use App\Models\StudyMode;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `index()` seeds FULL_TIME/PART_TIME the first time a tenant has zero rows
 * — see StudyMode's own docblock for why this is a real CRUD table rather
 * than a fixed Support enum. Not a provisioning-pipeline change: any tenant
 * created before this feature shipped gets the same defaults the instant
 * an admin first opens the Study Modes page.
 */
final class StudyModeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudyMode::class);

        $this->ensureDefaults();

        $modes = ApiQuery::for(StudyMode::query(), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['sort_order', 'name', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(StudyModeResource::collection($modes));
    }

    public function store(StoreStudyModeRequest $request): JsonResponse
    {
        $mode = StudyMode::query()->create($request->validated());

        return ApiResponse::created(new StudyModeResource($mode));
    }

    public function show(StudyMode $studyMode): JsonResponse
    {
        $this->authorize('view', $studyMode);

        return ApiResponse::success(new StudyModeResource($studyMode));
    }

    public function update(UpdateStudyModeRequest $request, StudyMode $studyMode): JsonResponse
    {
        $studyMode->update($request->validated());

        return ApiResponse::success(new StudyModeResource($studyMode));
    }

    public function destroy(StudyMode $studyMode): JsonResponse
    {
        $this->authorize('delete', $studyMode);

        $studyMode->delete();

        return ApiResponse::noContent();
    }

    private function ensureDefaults(): void
    {
        if (StudyMode::query()->exists()) {
            return;
        }

        StudyMode::query()->create(['code' => StudyMode::FULL_TIME, 'name' => 'Full Time', 'sort_order' => 0]);
        StudyMode::query()->create(['code' => StudyMode::PART_TIME, 'name' => 'Part Time', 'sort_order' => 1]);
    }
}
