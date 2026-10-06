<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin\Concerns;

use App\Models\Department;
use App\Models\Staff;
use App\Models\Team;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Where a staff member sits in HRM > Organization Management — shared by
 * StoreStaffRequest and UpdateStaffRequest. Every field is optional, but the
 * picks must agree with each other: a team inside the chosen department, a
 * department inside the chosen branch, and a reporting manager who isn't
 * this person or anyone who (directly or not) reports to them.
 */
trait ValidatesStaffOrganization
{
    /**
     * @return array<string, list<mixed>>
     */
    protected function organizationRules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists('tenant.branches', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('tenant.departments', 'id')],
            'team_id' => ['nullable', 'integer', Rule::exists('tenant.teams', 'id')],
            'job_grade_id' => ['nullable', 'integer', Rule::exists('tenant.job_grades', 'id')],
            'job_level_id' => ['nullable', 'integer', Rule::exists('tenant.job_levels', 'id')],
            'reports_to_staff_id' => ['nullable', 'integer', Rule::exists('tenant.staff', 'id')->whereNull('deleted_at')],
        ];
    }

    /**
     * The value each field will hold once saved: the request's own when sent,
     * otherwise what the staff member already has (an update may send only
     * some of them).
     */
    private function organizationValue(string $field, ?Staff $staff): ?int
    {
        $value = $this->has($field) ? $this->input($field) : $staff?->{$field};

        return $value === null || $value === '' ? null : (int) $value;
    }

    protected function validateOrganization(Validator $validator, ?Staff $staff): void
    {
        $validator->after(function (Validator $validator) use ($staff) {
            $errors = $validator->errors();

            $branchId = $this->organizationValue('branch_id', $staff);
            $departmentId = $this->organizationValue('department_id', $staff);
            $teamId = $this->organizationValue('team_id', $staff);

            if ($departmentId !== null && $branchId !== null && ! $errors->has('department_id')) {
                $departmentBranchId = Department::query()->whereKey($departmentId)->value('branch_id');
                if ($departmentBranchId !== null && (int) $departmentBranchId !== $branchId) {
                    $errors->add('department_id', __('This department belongs to a different branch.'));
                }
            }

            if ($teamId !== null && $departmentId !== null && ! $errors->has('team_id')) {
                $teamDepartmentId = Team::query()->whereKey($teamId)->value('department_id');
                if ($teamDepartmentId !== null && (int) $teamDepartmentId !== $departmentId) {
                    $errors->add('team_id', __('This team belongs to a different department.'));
                }
            }

            $managerId = $this->organizationValue('reports_to_staff_id', $staff);
            if ($staff === null || $managerId === null || $errors->has('reports_to_staff_id')) {
                return;
            }

            // Walk up the chain from the chosen manager: reaching this person
            // means the pick would make them (indirectly) their own manager.
            $seen = [];
            $current = $managerId;
            while ($current !== null && ! isset($seen[$current])) {
                if ($current === $staff->id) {
                    $errors->add('reports_to_staff_id', __('A staff member cannot report to themselves or to someone who reports to them.'));

                    return;
                }

                $seen[$current] = true;
                $next = Staff::query()->whereKey($current)->value('reports_to_staff_id');
                $current = $next === null ? null : (int) $next;
            }
        });
    }
}
