<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\CoursePackage;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'price' => ['sometimes', 'required', 'numeric', 'min:0', 'max:999999.99'],
            'duration' => ['nullable', 'string', 'max:50'],
            'is_active' => ['sometimes', 'boolean'],
            'book_ids' => ['sometimes', 'array', 'min:1'],
            'book_ids.*' => [Rule::exists('books', 'id')->where('tenant_id', $tenantId)],
        ];
    }
}
