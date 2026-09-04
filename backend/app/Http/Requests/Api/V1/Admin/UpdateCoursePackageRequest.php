<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CoursePackage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCoursePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var CoursePackage $coursePackage */
        $coursePackage = $this->route('course_package');

        return $this->user()?->can('update', $coursePackage) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        /** @var CoursePackage $coursePackage */
        $coursePackage = $this->route('course_package');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:32', Rule::unique('course_packages')->where('tenant_id', $tenantId)->ignore($coursePackage)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'academic_program_id' => ['sometimes', 'required', Rule::exists('academic_programs', 'id')->where('tenant_id', $tenantId)],
            'description' => ['nullable', 'string', 'max:2000'],
            'thumbnail' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'currency' => ['sometimes', 'required', Rule::in([CoursePackage::CURRENCY_USD, CoursePackage::CURRENCY_KHR])],
            'fee_monthly' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_term' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_video' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_monthly_online' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'fee_term_online' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999.99'],
            'duration' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'show_on_website' => ['sometimes', 'boolean'],
            'show_in_popular' => ['sometimes', 'boolean'],
            'show_videos' => ['sometimes', 'boolean'],
            'book_ids' => ['sometimes', 'array', 'min:1'],
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $fees = ['fee_monthly', 'fee_term', 'fee_video', 'fee_monthly_online', 'fee_term_online'];

            // Only enforced when at least one fee field was actually
            // submitted — a PATCH that doesn't touch fees at all (renaming
            // the package, say) must not be forced to also resend them.
            if (! collect($fees)->contains(fn ($field) => $this->has($field))) {
                return;
            }

            /** @var CoursePackage $coursePackage */
            $coursePackage = $this->route('course_package');

            $stillHasAFee = collect($fees)->contains(
                fn ($field) => $this->input($field, $coursePackage->{$field}) !== null,
            );

            if (! $stillHasAFee) {
                $validator->errors()->add('fee_monthly', __('Set at least one fee (monthly, term, video, or online).'));
            }
        });
    }
}
