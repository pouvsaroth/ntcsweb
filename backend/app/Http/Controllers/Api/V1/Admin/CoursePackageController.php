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
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class CoursePackageController extends Controller
{
    public function __construct(
        private readonly CoursePackageService $packages,
        private readonly TenantContext $context,
    ) {}

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
        $data = $request->safe()->except('thumbnail');

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = $this->storeThumbnail($request);
        }

        $package = $this->packages->create($data);

        return ApiResponse::created(new CoursePackageResource($package));
    }

    public function show(CoursePackage $coursePackage): JsonResponse
    {
        $this->authorize('view', $coursePackage);

        return ApiResponse::success(new CoursePackageResource($coursePackage->load(['academicProgram', 'books'])));
    }

    public function update(UpdateCoursePackageRequest $request, CoursePackage $coursePackage): JsonResponse
    {
        $data = $request->safe()->except('thumbnail');
        $previousPath = $coursePackage->thumbnail_path;

        if ($request->hasFile('thumbnail')) {
            $data['thumbnail_path'] = $this->storeThumbnail($request);
        }

        $package = $this->packages->update($coursePackage, $data);

        // Only removed after the new path is safely persisted — if the
        // update above had failed, the old file must still be there to fall
        // back on. Mirrors HomeSlideController::update().
        if ($request->hasFile('thumbnail') && $previousPath !== null) {
            Storage::disk('public')->delete($previousPath);
        }

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

    private function storeThumbnail(StoreCoursePackageRequest|UpdateCoursePackageRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('thumbnail')->store($tenant->storagePath('course-package-thumbnails'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded thumbnail.');
        }

        return $path;
    }
}
