<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Public;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Public\StoreStudentRegistrationRequest;
use App\Http\Responses\ApiResponse;
use App\Models\CoursePackage;
use App\Services\Academic\EnrollmentService;
use App\Services\Billing\DiscountCodeService;
use App\Services\Academic\StudentRegistrationService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /**
     * The form's live discount-code check: what a code takes off the chosen
     * course's fee. A new student has no personal codes, so only public ones
     * work. Nothing is saved.
     */
    public function checkDiscountCode(Request $request, DiscountCodeService $discounts, EnrollmentService $enrollments): JsonResponse
    {
        $data = $request->validate([
            'discount_code' => ['required', 'string', 'max:40'],
            'course_package_id' => ['required', 'integer', Rule::exists('tenant.course_packages', 'id')],
            'fee_type' => ['required', Rule::in(['monthly', 'term', 'video', 'monthly_online', 'term_online'])],
        ]);

        return ApiResponse::success($discounts->checkForEnrollment(
            $data['discount_code'],
            null,
            CoursePackage::query()->findOrFail($data['course_package_id']),
            $data['fee_type'],
            $enrollments,
        ));
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
