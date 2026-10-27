<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\ClassroomTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Many tables in one go: either a numbered range (prefix + 01..N) or a copy
 * of another room's tables.
 */
class BulkStoreClassroomTablesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', ClassroomTable::class) ?? false;
    }

    public function rules(): array
    {
        $copying = $this->filled('copy_from_classroom_id');

        return [
            'classroom_id' => ['required', Rule::exists('tenant.classrooms', 'id')],
            'copy_from_classroom_id' => ['nullable', Rule::exists('tenant.classrooms', 'id'), 'different:classroom_id'],
            'prefix' => ['nullable', 'string', 'max:200'],
            'count' => [$copying ? 'nullable' : 'required', 'integer', 'min:1', 'max:500'],
            'start_number' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'allow_many_students' => ['sometimes', 'boolean'],
        ];
    }
}
