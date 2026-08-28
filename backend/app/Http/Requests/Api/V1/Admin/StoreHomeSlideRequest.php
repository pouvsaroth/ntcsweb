<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\HomeSlide;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHomeSlideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', HomeSlide::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // 10M matches upload_max_filesize in docker/php/uploads.ini —
            // raising one without the other just moves where the rejection
            // happens (a less friendly PHP-level cutoff instead of this
            // validation message).
            'image' => ['required', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],
            'title' => ['nullable', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
            'link_url' => ['nullable', 'url', 'max:2048'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', Rule::in([HomeSlide::STATUS_ACTIVE, HomeSlide::STATUS_INACTIVE])],
        ];
    }
}
