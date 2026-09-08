<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Language;
use App\Models\LookupValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateLookupValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var LookupValue $lookupValue */
        $lookupValue = $this->route('lookup_value');

        return $this->user()?->can('update', $lookupValue) ?? false;
    }

    public function rules(): array
    {
        return [
            'lookup_category_id' => ['sometimes', 'required', Rule::exists('tenant.lookup_categories', 'id')],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_]+$/'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'translations' => ['sometimes', 'array'],
            'translations.*.name' => ['nullable', 'string', 'max:255'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var LookupValue $lookupValue */
            $lookupValue = $this->route('lookup_value');
            $categoryId = $this->input('lookup_category_id', $lookupValue->lookup_category_id);
            $code = $this->input('code', $lookupValue->code);

            if (LookupValue::query()->where('lookup_category_id', $categoryId)
                ->where('code', $code)->whereKeyNot($lookupValue->getKey())->exists()
            ) {
                $validator->errors()->add('code', 'This code is already used in the selected category.');
            }

            $translations = $this->input('translations', []);
            $knownCodes = Language::query()->pluck('code')->all();

            foreach (array_keys($translations) as $languageCode) {
                if (! in_array($languageCode, $knownCodes, true)) {
                    $validator->errors()->add("translations.{$languageCode}", "Unknown language code: {$languageCode}.");
                }
            }
        });
    }
}
