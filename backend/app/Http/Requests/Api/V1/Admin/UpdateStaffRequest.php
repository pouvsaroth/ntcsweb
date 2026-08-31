<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Same "no role field" rule as StoreStaffRequest — changing `position_id` is
 * how a Staff member's role changes; see StaffController::update().
 */
class UpdateStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Staff $staff */
        $staff = $this->route('staff');

        return $this->user()?->can('update', $staff) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();
        $staff = $this->route('staff');

        return [
            'employee_code' => [
                'sometimes', 'required', 'string', 'max:32',
                Rule::unique('staff')->where('tenant_id', $tenantId)->ignore($staff),
            ],
            'position_id' => ['sometimes', 'required', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE])],
        ];
    }
}
