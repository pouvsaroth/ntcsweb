<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DownloadFolder;
use Illuminate\Foundation\Http\FormRequest;

class StoreDownloadFileRequest extends FormRequest
{
    /**
     * Anyone may download these from the public site, so only ordinary
     * document/picture/archive types — never anything a browser would run
     * (html, svg, js) if opened straight from storage.
     */
    public const EXTENSIONS = 'jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,psd,ai,zip,rar,7z,mp3,mp4';

    public function authorize(): bool
    {
        return $this->user()?->can('create', DownloadFolder::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // One file per request (the admin page uploads a multi-select one
            // by one), so 50M matches upload_max_filesize in docker/php/uploads.ini.
            'file' => ['required', 'file', 'max:51200', 'extensions:'.self::EXTENSIONS],
            'name' => ['nullable', 'string', 'max:255'],
        ];
    }
}
