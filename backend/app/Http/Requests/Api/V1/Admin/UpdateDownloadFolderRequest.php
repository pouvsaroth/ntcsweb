<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDownloadFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DownloadFolder $folder */
        $folder = $this->route('download_folder');

        return $this->user()?->can('update', $folder) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([DownloadFolder::STATUS_ACTIVE, DownloadFolder::STATUS_INACTIVE])],
        ];
    }
}
