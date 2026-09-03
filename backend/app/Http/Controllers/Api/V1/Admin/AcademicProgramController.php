<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreAcademicProgramRequest;
use App\Http\Requests\Api\V1\Admin\UpdateAcademicProgramRequest;
use App\Http\Resources\AcademicProgramResource;
use App\Http\Responses\ApiResponse;
use App\Models\AcademicProgram;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcademicProgramController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AcademicProgram::class);

        $programs = ApiQuery::for(AcademicProgram::query(), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active'])
            ->sortable(['sort_order', 'name', 'created_at'], default: 'sort_order')
            ->paginate();

        return ApiResponse::success(AcademicProgramResource::collection($programs));
    }

    public function store(StoreAcademicProgramRequest $request): JsonResponse
    {
        $program = AcademicProgram::query()->create($request->validated());

        return ApiResponse::created(new AcademicProgramResource($program));
    }

    public function show(AcademicProgram $academicProgram): JsonResponse
    {
        $this->authorize('view', $academicProgram);

        return ApiResponse::success(new AcademicProgramResource($academicProgram->load('books')));
    }

    public function update(UpdateAcademicProgramRequest $request, AcademicProgram $academicProgram): JsonResponse
    {
        $academicProgram->update($request->validated());

        return ApiResponse::success(new AcademicProgramResource($academicProgram->load('books')));
    }

    public function destroy(AcademicProgram $academicProgram): JsonResponse
    {
        $this->authorize('delete', $academicProgram);

        if ($academicProgram->coursePackages()->exists() || $academicProgram->classes()->exists()) {
            return ApiResponse::error('This program is in use and cannot be deleted. Deactivate it instead.', 422);
        }

        $academicProgram->delete();

        return ApiResponse::noContent();
    }
}
