<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Public;

use App\Models\CoursePackage;
use App\Support\Billing\PaymentMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Unauthenticated by design — a visitor filling out the self-registration
 * wizard has no account yet. `tenant.required` on the route group is the
 * only gate. Deliberately has no `fee`/`price`/`student_code` field for the
 * same reason StoreEnrollmentPackageRequest doesn't — both are always
 * server-derived, never client-supplied.
 *
 * `payment_method` accepts `CASH` or `QR` (Bakong KHQR — see App\Support\
 * Billing\Khqr) — either way, nothing is actually billed until an admin
 * confirms it at approval time; see StudentRegistrationService.
 */
class StoreStudentRegistrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],

            'house_no' => ['nullable', 'string', 'max:10'],
            'street_no' => ['nullable', 'string', 'max:10'],
            'village_code' => ['nullable', 'string', 'max:20'],
            'other_address' => ['nullable', 'string', 'max:150'],

            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'class_id' => ['required', Rule::exists('classes', 'id')->where('tenant_id', $tenantId)],
            'course_package_id' => ['required', Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId)],
            'fee_type' => ['required', Rule::in(['monthly', 'term', 'video', 'monthly_online', 'term_online'])],

            'payment_method' => ['required', Rule::in([PaymentMethod::CASH, PaymentMethod::QR])],

            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('course_package_id') || $validator->errors()->has('fee_type')) {
                return;
            }

            $package = CoursePackage::query()->find($this->input('course_package_id'));
            if ($package === null) {
                return;
            }

            $feeType = $this->input('fee_type');
            $feeColumn = "fee_{$feeType}";

            if ($package->{$feeColumn} === null) {
                $validator->errors()->add('fee_type', __('This package does not offer the selected fee type.'));
            }
        });
    }
}
