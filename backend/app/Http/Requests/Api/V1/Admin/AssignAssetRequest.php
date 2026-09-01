<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssignableType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `assignable_type` is one of AssignableType::options()'s short keys
 * ('staff', 'student', 'user', 'department', 'classroom') — the controller
 * resolves it to the whitelisted FQCN and loads the target row, so a client
 * can never write an arbitrary class name into `asset_assignments.assignable_type`.
 */
class AssignAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return $this->user()?->can('assign', $asset) ?? false;
    }

    public function rules(): array
    {
        return [
            'assignable_type' => ['required', Rule::in(array_keys(AssignableType::options()))],
            'assignable_id' => ['required', 'integer'],
            'assigned_date' => ['nullable', 'date'],
            'expected_return_date' => ['nullable', 'date', 'after_or_equal:assigned_date'],
            'condition_at_assignment' => ['nullable', Rule::in(AssetCondition::all())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
