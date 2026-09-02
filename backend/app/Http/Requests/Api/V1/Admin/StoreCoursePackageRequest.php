<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CoursePackage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Never accepts a `product_id` — the linked billable Product is always created internally by CoursePackageService. */
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
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'duration' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'book_ids' => ['required', 'array', 'min:1'],
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
