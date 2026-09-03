<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CoursePackage;
use App\Models\Enrollment;
use App\Support\Billing\PaymentMethod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Deliberately has NO `fee`/`total`/`price` field — the fee is always
 * computed server-side from the course package's chosen `fee_type` tier
 * (see EnrollmentService::enrollInPackage()), never accepted from the
 * client. This is structural, not just a review-time convention.
 *
 * `discount_price`/`received_amount` ARE client-submitted (a discount
 * amount and how much cash was actually handed over — neither is
 * derivable from catalog data alone), but both are validated/capped
 * server-side: discount can't exceed the resolved fee, and the actual
 * `Payment` amount is capped at the invoice total regardless of what's
 * received (see EnrollmentService — the excess is "change", never
 * persisted as paid).
 */
class StoreEnrollmentPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Enrollment::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'student_id' => ['required', Rule::exists('students', 'id')->where('tenant_id', $tenantId)],
            'class_id' => ['required', Rule::exists('classes', 'id')->where('tenant_id', $tenantId)],

            // Mirrors StoreEnrollmentRequest's own book_id uniqueness check —
            // scoped to non-dropped rows so a student may re-enroll in the
            // same class+package after dropping (the DB's partial unique
            // index allows exactly this); without the status exclusion here
            // a re-enrollment would 422 even though the DB would accept it.
            'course_package_id' => [
                'required',
                Rule::exists('course_packages', 'id')->where('tenant_id', $tenantId),
                Rule::unique('enrollments')->where('tenant_id', $tenantId)->where(
                    fn ($query) => $query
                        ->where('student_id', $this->input('student_id'))
                        ->where('class_id', $this->input('class_id'))
                        ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ),
            ],
            // Uniqueness/room-membership is checked in withValidator() below
            // — mirrors StoreEnrollmentRequest's own table_id handling.
            'table_id' => ['nullable', Rule::exists('classroom_tables', 'id')->where('tenant_id', $tenantId)],

            'enrolled_at' => ['nullable', 'date'],

            // Which of the package's 5 fee tiers this enrollment is billed
            // under — checked against the package in withValidator() below
            // (the tier must actually be set on that package).
            'fee_type' => ['required', Rule::in(['monthly', 'term', 'video', 'monthly_online', 'term_online'])],

            'discount_reason' => ['nullable', 'string', 'max:50'],
            'discount_price' => ['nullable', 'numeric', 'min:0'],

            'received_amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['nullable', Rule::in(PaymentMethod::all())],
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
            $fee = $package->{$feeColumn};

            if ($fee === null) {
                $validator->errors()->add('fee_type', __('This package does not offer the selected fee type.'));

                return;
            }

            $discountPrice = $this->input('discount_price');
            if ($discountPrice !== null && (float) $discountPrice > (float) $fee) {
                $validator->errors()->add('discount_price', __('The discount cannot exceed the fee.'));
            }

            $receivedAmount = (float) ($this->input('received_amount') ?? 0);
            if ($receivedAmount > 0 && $this->input('payment_method') === null) {
                $validator->errors()->add('payment_method', __('A payment method is required when recording a payment.'));
            }
        });

        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('class_id') || $validator->errors()->has('table_id')) {
                return;
            }

            $class = DB::table('classes')->where('id', $this->input('class_id'))->first();
            if ($class === null || $class->classroom_id === null) {
                return;
            }

            $hasTables = DB::table('classroom_tables')->where('classroom_id', $class->classroom_id)->exists();
            if (! $hasTables) {
                return;
            }

            $tableId = $this->input('table_id');

            if ($tableId === null) {
                $validator->errors()->add('table_id', __('Pick a table for this class.'));

                return;
            }

            $belongsToRoom = DB::table('classroom_tables')->where('id', $tableId)->where('classroom_id', $class->classroom_id)->exists();
            if (! $belongsToRoom) {
                $validator->errors()->add('table_id', __("This table does not belong to the selected class's room."));

                return;
            }

            $taken = DB::table('enrollments')
                ->where('class_id', $this->input('class_id'))
                ->where('table_id', $tableId)
                ->where('status', '!=', Enrollment::STATUS_DROPPED)
                ->exists();

            if ($taken) {
                $validator->errors()->add('table_id', __('This table is already taken in this class.'));
            }
        });
    }
}
