<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAcademicYearRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAcademicYearRequest;
use App\Http\Resources\AcademicYearResource;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicYear;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicYearController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicYear::class);

        $years = ApiQuery::for(AcademicYear::query(), $request)
            ->searchable('name')
            ->filterable(['is_current'])
            ->sortable(['name', 'start_date', 'created_at'], default: '-name')
            ->paginate();

        return ApiResponse::success(AcademicYearResource::collection($years));
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $data = $request->validated();

        if ($data['is_current'] ?? false) {
            $this->clearCurrentFlag();
        }

        $year = AcademicYear::query()->create($data);

        return ApiResponse::created(new AcademicYearResource($year));
    }

    public function show(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('view', $academicYear);

        return ApiResponse::success(new AcademicYearResource($academicYear));
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $data = $request->validated();

        if ($data['is_current'] ?? false) {
            $this->clearCurrentFlag();
        }

        $academicYear->update($data);

        return ApiResponse::success(new AcademicYearResource($academicYear));
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        $this->authorize('delete', $academicYear);

        $academicYear->delete();

        return ApiResponse::noContent();
    }

    /** Only one academic year can be "current" per tenant at a time. */
    private function clearCurrentFlag(): void
    {
        AcademicYear::query()->where('is_current', true)->update(['is_current' => false]);
    }
}
