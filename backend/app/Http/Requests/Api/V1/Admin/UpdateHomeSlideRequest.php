<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\HomeSlide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A real file input can't be sent with a literal HTTP PUT from a browser —
 * the frontend sends `POST` with a spoofed `_method=PUT` field (Laravel's
 * standard method-override support, already enabled by default), which
 * still lands here via the normal apiResource `update` route.
 */
class UpdateHomeSlideRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var HomeSlide $slide */
        $slide = $this->route('home_slide');

        return $this->user()?->can('update', $slide) ?? false;
    }

    public function rules(): array
    {
        return [
            // Optional: an update that only changes the caption or order
            // shouldn't have to re-upload the image.
            'image' => ['sometimes', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([HomeSlide::STATUS_ACTIVE, HomeSlide::STATUS_INACTIVE])],
        ];
    }
}
