<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CoursePackage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Never accepts a `product_id` — the linked billable Product is always
 * created internally by CoursePackageService. Never accepts `price` either
 * — that legacy scalar is derived server-side from whichever fee tier is
 * set (see CoursePackageService), not typed directly.
 */
class StoreCoursePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', CoursePackage::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'code' => ['required', 'string', 'max:32', Rule::unique('course_packages')->where('tenant_id', $tenantId)],
            'name' => ['required', 'string', 'max:255'],
            'academic_program_id' => ['required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'description' => ['nullable', 'string', 'max:2000'],
            // 10M matches upload_max_filesize in docker/php/uploads.ini —
            // see StoreHomeSlideRequest for why both must move together.
            'thumbnail' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'currency' => ['required', Rule::in([CoursePackage::CURRENCY_USD, CoursePackage::CURRENCY_KHR])],
            'fee_monthly' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_term' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_video' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_monthly_online' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_term_online' => ['nullable', 'numeric', 'min:0', 'max:999999.99'],
            'duration' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_website' => ['sometimes', 'boolean'],
            'show_in_popular' => ['sometimes', 'boolean'],
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fees = ['fee_monthly', 'fee_term', 'fee_video', 'fee_monthly_online', 'fee_term_online'];

            if (collect($fees)->every(fn ($field) => $this->input($field) === null)) {
                $validator->errors()->add('fee_monthly', __('Set at least one fee (monthly, term, video, or online).'));
            }
        });
    }
}
