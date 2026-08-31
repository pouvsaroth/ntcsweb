<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The About page has a fixed layout (4 stat counters, one history block, 3
 * pillars, 4 achievements) rather than admin-managed lists, so the array
 * shape is validated exactly — `size:4`/`size:3`, not `min`/`max` — matching
 * the public page's fixed template rather than pretending it's an arbitrary
 * repeater. Every field within a row is optional, though: a school can save
 * the page with only some rows filled in rather than all-or-nothing.
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
            'history_title' => ['nullable', 'string', 'max:255'],
            'history_paragraph_1' => ['nullable', 'string', 'max:2000'],
            'history_paragraph_2' => ['nullable', 'string', 'max:2000'],
            // 10M matches upload_max_filesize in docker/php/uploads.ini.
            'history_image' => ['nullable', 'image', 'mimes:jpeg,png,webp,gif', 'max:10240'],

            // The array itself still has a fixed size — the frontend always
            // sends exactly this many rows (blank ones included) — but every
            // field inside a row is optional, so a school can publish a
            // partially-filled page instead of an all-or-nothing one.
            'stats' => ['nullable', 'array', 'size:4'],
            'stats.*.value' => ['nullable', 'string', 'max:20'],
            'stats.*.label' => ['nullable', 'string', 'max:100'],

            'pillars' => ['nullable', 'array', 'size:3'],
            'pillars.*.icon' => ['nullable', 'string', 'max:10'],
            'pillars.*.title' => ['nullable', 'string', 'max:100'],
            'pillars.*.description' => ['nullable', 'string', 'max:500'],

            'achievements_title' => ['nullable', 'string', 'max:255'],
            'achievements' => ['nullable', 'array', 'size:4'],
            'achievements.*.icon' => ['nullable', 'string', 'max:10'],
            'achievements.*.value' => ['nullable', 'string', 'max:20'],
            'achievements.*.label' => ['nullable', 'string', 'max:100'],
        ];
    }
}
