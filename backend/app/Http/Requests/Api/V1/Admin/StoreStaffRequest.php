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
 *
 * Also has no `profile_color` field — it is always server-generated (see
 * StaffController::profileColorFor()), never accepted from a request.
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

            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            // The legacy system's "alias/nickname" field — optional.
            'other_name' => ['nullable', 'string', 'max:255'],

            'gender' => ['nullable', 'string', 'max:10'],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'birth_place' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:50'],
            // Same shape as Student's photo upload — see StoreStudentRequest.
            'national_id_photo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            // Required, not email: this is what the auto-provisioned login
            // account (see StaffController::store()) is keyed on — a school
            // may have no email on file for a staff member, but always has a
            // phone number.
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'email:rfc', 'max:255'],

            // Structured to match the legacy system's address shape — see
            // the restructuring migration for the full field-by-field
            // correspondence. `village_code` is free text (not a foreign
            // key), resolved via GET /geo/lookup, same convention as Student.
            'house_no' => ['nullable', 'string', 'max:10'],
            'street_no' => ['nullable', 'string', 'max:10'],
            'village_code' => ['nullable', 'string', 'max:20'],

            'facebook' => ['nullable', 'string', 'max:255'],
            'telegram' => ['nullable', 'string', 'max:255'],
            'other_contact' => ['nullable', 'string', 'max:255'],

            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_INACTIVE])],
        ];
    }
}
