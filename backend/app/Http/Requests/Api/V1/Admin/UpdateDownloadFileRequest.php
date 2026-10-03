<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFile;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDownloadFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var DownloadFile $file */
        $file = $this->route('download_file');

        return $this->user()?->can('update', $file->folder) ?? false;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }
}
