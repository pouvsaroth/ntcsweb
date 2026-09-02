<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\StoreEnrollmentPackageRequest;
use App\Http\Resources\EnrollmentResource;
use App\Http\Responses\ApiResponse;
use App\Services\Academic\EnrollmentService;
use Illuminate\Http\JsonResponse;

/**
 * The package-based enrollment path — separate from EnrollmentController,
 * whose `store()` remains the legacy book-based path, untouched. See
 * EnrollmentService::enrollInPackage() for the full orchestration
 * (Enrollment + Invoice + InvoiceItem in one transaction).
 */
final class EnrollmentPackageController extends Controller
{
    public function __construct(private readonly EnrollmentService $enrollments) {}

    public function store(StoreEnrollmentPackageRequest $request): JsonResponse
    {
        $enrollment = $this->enrollments->enrollInPackage($request->validated(), $request->user());

        return ApiResponse::created(new EnrollmentResource($enrollment));
    }
}
