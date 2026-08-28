<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreEnrollmentRequest;
use App\Http\Requests\Api\V1\Admin\UpdateEnrollmentRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Enrollment;
use App\Support\Query\ApiQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class EnrollmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $enrollments = ApiQuery::for(
            Enrollment::query()->with(['student', 'schoolClass']),
            $request,
        )
            ->filterable(['status', 'student_id', 'class_id'])
            ->sortable(['enrolled_at', 'created_at'], default: '-created_at')
            ->paginate();

        return ApiResponse::success(EnrollmentResource::collection($enrollments));
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = Enrollment::query()->create($request->validated());

        return ApiResponse::created(new EnrollmentResource($enrollment->load(['student', 'schoolClass'])));
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        return ApiResponse::success(new EnrollmentResource($enrollment->load(['student', 'schoolClass'])));
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment->update($request->validated());

        return ApiResponse::success(new EnrollmentResource($enrollment->load(['student', 'schoolClass'])));
    }

    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('delete', $enrollment);

        $enrollment->delete();

        return ApiResponse::noContent();
    }
}
