<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\GalleryImage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A real file input can't be sent with a literal HTTP PUT from a browser —
 * see UpdateHomeSlideRequest for the same POST + `_method=PUT` note.
 *
 * Validation rules only: the back office's website controllers use them
 * (docs/backoffice/stage-7-website.md).
 */
class UpdateGalleryImageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Optional: an update that only changes the caption or order
            // shouldn't have to re-upload the image.
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'caption' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([GalleryImage::STATUS_ACTIVE, GalleryImage::STATUS_INACTIVE])],
        ];
    }
}
