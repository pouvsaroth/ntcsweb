<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Staff;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Same "no role field" rule as StoreStaffRequest — changing `position_id` is
 * how a Staff member's role changes; see StaffController::update(). Also has
 * no `profile_color` field — it is set once at creation and never
 * regenerated (see StaffController::store()).
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

            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'other_name' => ['nullable', 'string', 'max:255'],

            'gender' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            // Optional on update — see UpdateStudentRequest for why (an edit
            // that doesn't touch the photo shouldn't have to re-upload it).
            'national_id_photo' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'phone' => ['sometimes', 'required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],

            'house_no' => ['nullable', 'string', 'max:10'],
            'street_no' => ['nullable', 'string', 'max:10'],
            'village_code' => ['nullable', 'string', 'max:20'],

            'facebook' => ['nullable', 'string', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],
            'other_contact' => ['nullable', 'string', 'max:255'],

            'photo' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE])],
        ];
    }
}
