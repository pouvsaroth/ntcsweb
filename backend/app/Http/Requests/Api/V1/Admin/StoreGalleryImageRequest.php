<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\GalleryImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Validation rules only: the back office's website controllers use them (docs/backoffice/stage-7-website.md). */
class StoreGalleryImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // 10M matches upload_max_filesize in docker/php/uploads.ini —
            // see StoreHomeSlideRequest for why both have to agree.
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([GalleryImage::STATUS_ACTIVE, GalleryImage::STATUS_INACTIVE])],
        ];
    }
}
