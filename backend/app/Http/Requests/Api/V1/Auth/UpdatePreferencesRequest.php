<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * My account → Preferences. Every field is optional: the page saves one
 * choice at a time, the moment it is picked.
 */
class UpdatePreferencesRequest extends FormRequest
{
    public const THEMES = ['system', 'light', 'dark'];

    public const CLOCKS = ['24', '12'];

    public const LOCALES = ['en', 'km', 'ja', 'ko', 'zh'];

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'locale' => ['sometimes', 'string', 'in:'.implode(',', self::LOCALES)],
            'theme' => ['sometimes', 'string', 'in:'.implode(',', self::THEMES)],
            // null = back to the school's colour.
            'accent' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'clock' => ['sometimes', 'string', 'in:'.implode(',', self::CLOCKS)],
        ];
    }
}
