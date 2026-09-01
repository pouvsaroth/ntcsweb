<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Asset;
use App\Support\Assets\AssetCondition;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReturnAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Asset $asset */
        $asset = $this->route('asset');

        return $this->user()?->can('returnAsset', $asset) ?? false;
    }

    public function rules(): array
    {
        return [
            'condition_at_return' => ['nullable', Rule::in(AssetCondition::all())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
