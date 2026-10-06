<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create/update for every OrganizationUnitController list. The table comes
 * from the route's own URI segment (branches, teams, job-grades,
 * job-levels), so one request covers all four; the permission check itself
 * is the controller's authorize() against OrganizationUnitPolicy.
 */
class OrganizationUnitRequest extends FormRequest
{
    /** URI segment => tenant table, for the unique-code rule. */
    private const TABLES = [
        'branches' => 'branches',
        'teams' => 'teams',
        'job-grades' => 'job_grades',
        'job-levels' => 'job_levels',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $segment = collect(self::TABLES)->keys()->first(fn (string $key) => in_array($key, $this->segments(), true));
        $table = self::TABLES[$segment];
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        $rules = [
            'code' => [$required, 'string', 'max:20', Rule::unique("tenant.{$table}", 'code')->ignore($this->route('id'))],
            'name' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if ($table === 'branches') {
            $rules['phone'] = ['nullable', 'string', 'max:50'];
            $rules['address'] = ['nullable', 'string', 'max:500'];
        }

        if ($table === 'teams') {
            $rules['department_id'] = ['nullable', 'integer', Rule::exists('tenant.departments', 'id')];
        }

        return $rules;
    }
}
