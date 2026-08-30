<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deliberately has no `role`/`role_id` field. The role is always derived
 * server-side from `position_id` (see StaffController::store()) — accepting
 * one here would let a client hand-pick their own privileges.
 */
class StoreStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Staff::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'employee_code' => ['required', 'string', 'max:32', Rule::unique('staff')->where('tenant_id', $tenantId)],

            // Must belong to this school — route-model binding also enforces
            // this via Position's own tenant scope, but a request-level 404
            // reads better than a policy-layer one for a bad foreign key.
            'position_id' => ['required', Rule::exists('positions', 'id')->where('tenant_id', $tenantId)],

            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE])],
        ];
    }
}
