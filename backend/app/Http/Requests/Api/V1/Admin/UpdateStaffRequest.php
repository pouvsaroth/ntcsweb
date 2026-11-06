<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Api\V1\Admin\Concerns\ValidatesStaffOrganization;
use App\Models\Staff;
use App\Policies\StaffPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Same "no role field" rule as StoreStaffRequest — changing `position_id` is
 * how a Staff member's role changes; see StaffController::update(). Also has
 * no `profile_color` field — it is set once at creation and never
 * regenerated (see StaffController::store()). Same for `employee_code` — see
 * StaffIdGenerator; not editable once assigned.
 */
class UpdateStaffRequest extends FormRequest
{
    use ValidatesStaffOrganization;

    /** Work fields a staff member editing their own profile can't change (Position also sets their login role). */
    public const OWN_PROFILE_LOCKED = [
        'position_id', 'hire_date', 'status',
        'branch_id', 'department_id', 'team_id', 'job_grade_id', 'job_level_id', 'reports_to_staff_id',
    ];

    /** Own-profile edits: the locked work fields are dropped, so they keep their saved values. */
    protected function prepareForValidation(): void
    {
        $user = $this->user();
        if ($user !== null && StaffPolicy::ownProfileOnly($user)) {
            foreach (self::OWN_PROFILE_LOCKED as $field) {
                $this->request->remove($field);
                $this->query->remove($field);
                if ($this->isJson()) {
                    $this->json()->remove($field);
                }
            }
        }
    }

    public function authorize(): bool
    {
        /** @var Staff $staff */
        $staff = $this->route('staff');

        return $this->user()?->can('update', $staff) ?? false;
    }

    public function rules(): array
    {
        return [
            'position_id' => ['sometimes', 'required', Rule::exists('tenant.positions', 'id')],

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
            'signature' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'hire_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(Staff::STATUSES_MANAGEABLE)],

            ...$this->organizationRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $this->validateOrganization($validator, $this->route('staff'));
    }
}
