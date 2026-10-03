<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDownloadFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DownloadFolder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // Two levels at most: a sub-folder can only go inside a top folder.
            'parent_id' => ['nullable', 'integer', Rule::exists('tenant.download_folders', 'id')->whereNull('parent_id')],
            'name' => ['required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([DownloadFolder::STATUS_ACTIVE, DownloadFolder::STATUS_INACTIVE])],
        ];
    }

    public function messages(): array
    {
        return [
            'parent_id.exists' => 'A sub-folder can only be created inside a top-level folder.',
        ];
    }
}
