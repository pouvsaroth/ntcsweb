<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Language;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLanguageRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Language $language */
        $language = $this->route('language');

        return $this->user()?->can('update', $language) ?? false;
    }

    public function rules(): array
    {
        /** @var Language $language */
        $language = $this->route('language');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:10', Rule::unique('languages', 'code')->ignore($language)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'native_name' => ['sometimes', 'required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
