<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreProgramOfferingRequest;
use App\Http\Requests\Api\V1\Admin\UpdateProgramOfferingRequest;
use App\Http\Resources\ProgramOfferingResource;
use App\Http\Responses\ApiResponse;
use App\Models\ProgramOffering;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProgramOfferingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ProgramOffering::class);

        $offerings = ApiQuery::for(ProgramOffering::query()->with(['academicProgram', 'studyMode', 'academicYear']), $request)
            ->filterable(['status', 'academic_program_id', 'study_mode_id', 'academic_year_id'])
            ->sortable(['created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(ProgramOfferingResource::collection($offerings));
    }

    public function store(StoreProgramOfferingRequest $request): JsonResponse
    {
        $offering = ProgramOffering::query()->create($request->validated());

        return ApiResponse::created(new ProgramOfferingResource($offering->load(['academicProgram', 'studyMode', 'academicYear'])));
    }

    public function show(ProgramOffering $programOffering): JsonResponse
    {
        $this->authorize('view', $programOffering);

        return ApiResponse::success(new ProgramOfferingResource($programOffering->load(['academicProgram', 'studyMode', 'academicYear'])));
    }

    public function update(UpdateProgramOfferingRequest $request, ProgramOffering $programOffering): JsonResponse
    {
        $programOffering->update($request->validated());

        return ApiResponse::success(new ProgramOfferingResource($programOffering->load(['academicProgram', 'studyMode', 'academicYear'])));
    }

    public function destroy(ProgramOffering $programOffering): JsonResponse
    {
        $this->authorize('delete', $programOffering);

        if ($programOffering->classes()->exists()) {
            return ApiResponse::error('This program offering has classes linked to it and cannot be deleted. Close it instead.', 422);
        }

        $programOffering->delete();

        return ApiResponse::noContent();
    }
}
