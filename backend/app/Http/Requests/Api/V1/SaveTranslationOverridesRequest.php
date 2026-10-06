<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Models\TranslationOverride;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A batch of the school's own words — see TranslationOverrideController.
 * Keys are the frontend's dotted message keys (adminNav.items.students), so
 * only letters, digits, `_`, `-` and dots are accepted.
 */
class SaveTranslationOverridesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permissions::BASE_DATA_MANAGE_TRANSLATIONS) ?? false;
    }

    public function rules(): array
    {
        return [
            'changes' => ['required', 'array', 'min:1', 'max:2000'],
            'changes.*.locale' => ['required', 'string', Rule::in(TranslationOverride::LOCALES)],
            'changes.*.key' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$/'],
            'changes.*.value' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
