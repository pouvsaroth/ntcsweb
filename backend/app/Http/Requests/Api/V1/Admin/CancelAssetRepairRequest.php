<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\AssetRepair;
use Illuminate\Foundation\Http\FormRequest;

class CancelAssetRepairRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var AssetRepair $repair */
        $repair = $this->route('asset_repair');

        return $this->user()?->can('update', $repair) ?? false;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }
}
