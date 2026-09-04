<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StoreStudentRegistrationRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Academic\StudentRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The public registration wizard's final submit — see
 * StudentRegistrationService for what actually happens (a pending User +
 * Student + Enrollment + Invoice, all inactive/unpaid until an admin
 * approves via Admin\StudentRegistrationController).
 */
final class StudentRegistrationController extends Controller
{
    public function __construct(
        private readonly StudentRegistrationService $registrations,
        private readonly TenantContext $context,
    ) {}

    public function store(StoreStudentRegistrationRequest $request): JsonResponse
    {
        $student = $this->registrations->register([
            ...$request->safe()->except('photo'),
            'photo_path' => $request->hasFile('photo') ? $this->storePhoto($request) : null,
        ]);

        return ApiResponse::created(
            ['student_code' => $student->student_code],
            __('Registration received — please wait for the school to confirm your payment and approve your account. You can safely close this page now.'),
        );
    }

    private function storePhoto(StoreStudentRegistrationRequest $request): string
    {
        $tenant = $this->context->getOrFail();

        $path = $request->file('photo')->store($tenant->storagePath('students'), 'public');

        if ($path === false) {
            abort(500, 'Failed to store the uploaded photo.');
        }

        return $path;
    }
}
