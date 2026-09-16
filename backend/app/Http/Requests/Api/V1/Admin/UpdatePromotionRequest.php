<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A real file input can't be sent with a literal HTTP PUT from a browser —
 * see UpdateGalleryImageRequest for the same POST + `_method=PUT` note.
 */
class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Promotion $promotion */
        $promotion = $this->route('promotion');

        return $this->user()?->can('update', $promotion) ?? false;
    }

    public function rules(): array
    {
        return [
            // Optional: an update that only changes the title or order
            // shouldn't have to re-upload the image.
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'title' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([Promotion::STATUS_ACTIVE, Promotion::STATUS_INACTIVE])],
        ];
    }
}
