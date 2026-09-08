<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransferAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return $this->user()?->can('transfer', $asset) ?? false;
    }

    public function rules(): array
    {
        return [
            'to_location_id' => ['nullable', Rule::exists('tenant.asset_locations', 'id')],
            'to_department_id' => ['nullable', Rule::exists('tenant.departments', 'id')],
            'reason' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
