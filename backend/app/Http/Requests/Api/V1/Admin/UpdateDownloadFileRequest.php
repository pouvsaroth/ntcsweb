<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFile;
use Illuminate\Foundation\Http\FormRequest;

/** Validation rules only: the back office's website controllers use them (docs/backoffice/stage-7-website.md). */
class UpdateDownloadFileRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
