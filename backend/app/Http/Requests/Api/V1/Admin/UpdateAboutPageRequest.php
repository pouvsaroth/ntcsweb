<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The About page has a fixed layout (4 stat counters, one history block, 3
 * pillars, 4 achievements) rather than admin-managed lists, so the shape is
 * validated exactly — `size:4`/`size:3`, not `min`/`max` — matching the
 * public page's fixed template rather than pretending it's an arbitrary
 * repeater.
 */
class UpdateAboutPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::TENANT_SETTINGS_UPDATE) ?? false;
    }

    public function rules(): array
    {
        return [
            'history_title' => ['required', 'string', 'max:255'],
            'history_paragraph_1' => ['required', 'string', 'max:2000'],
            'history_paragraph_2' => ['nullable', 'string', 'max:2000'],
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'history_image' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            'stats' => ['required', 'array', 'size:4'],
            'stats.*.value' => ['required', 'string', 'max:20'],
            'stats.*.label' => ['required', 'string', 'max:100'],

            'pillars' => ['required', 'array', 'size:3'],
            'pillars.*.icon' => ['required', 'string', 'max:10'],
            'pillars.*.title' => ['required', 'string', 'max:100'],
            'pillars.*.description' => ['required', 'string', 'max:500'],

            'achievements_title' => ['required', 'string', 'max:255'],
            'achievements' => ['required', 'array', 'size:4'],
            'achievements.*.icon' => ['required', 'string', 'max:10'],
            'achievements.*.value' => ['required', 'string', 'max:20'],
            'achievements.*.label' => ['required', 'string', 'max:100'],
        ];
    }
}
