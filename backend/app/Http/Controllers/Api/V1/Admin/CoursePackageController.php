<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreCoursePackageRequest;
use App\Http\Requests\Api\V1\Admin\UpdateCoursePackageRequest;
use App\Http\Resources\CoursePackageResource;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Services\Academic\CoursePackageService;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CoursePackageController extends Controller
{
    public function __construct(private readonly CoursePackageService $packages) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', CoursePackage::class);

        $packages = ApiQuery::for(CoursePackage::query()->with(['academicProgram', 'books']), $request)
            ->searchable('code', 'name')
            ->filterable(['is_active', 'academic_program_id'])
            ->sortable(['name', 'price', 'created_at'], default: 'name')
            ->paginate();

        return ApiResponse::success(CoursePackageResource::collection($packages));
    }

    public function store(StoreCoursePackageRequest $request): JsonResponse
    {
        $package = $this->packages->create($request->validated());

        return ApiResponse::created(new CoursePackageResource($package));
    }

    public function show(CoursePackage $coursePackage): JsonResponse
    {
        $this->authorize('view', $coursePackage);

        return ApiResponse::success(new CoursePackageResource($coursePackage->load(['academicProgram', 'books'])));
    }

    public function update(UpdateCoursePackageRequest $request, CoursePackage $coursePackage): JsonResponse
    {
        $package = $this->packages->update($coursePackage, $request->validated());

        return ApiResponse::success(new CoursePackageResource($package));
    }

    public function destroy(CoursePackage $coursePackage): JsonResponse
    {
        $this->authorize('delete', $coursePackage);

        if ($coursePackage->enrollments()->exists()) {
            return ApiResponse::error('This package has enrollments and cannot be deleted. Deactivate it instead.', 422);
        }

        $coursePackage->delete();

        return ApiResponse::noContent();
    }
}
