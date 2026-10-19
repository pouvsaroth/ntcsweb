<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFolder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation rules only: the back office's website controllers use them (docs/backoffice/stage-7-website.md). */
class UpdateDownloadFolderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([DownloadFolder::STATUS_ACTIVE, DownloadFolder::STATUS_INACTIVE])],
        ];
    }
}
