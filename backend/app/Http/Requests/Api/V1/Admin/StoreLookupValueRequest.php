<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\Language;
use App\Models\LookupValue;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * `translations` is keyed by language CODE (e.g. `en`, `km`), not id — the
 * admin form edits every configured language in one screen at once (see
 * LookupValueService::syncTranslations()). At minimum, the platform's
 * default language's name is required on create, matching the spec's own
 * "English required if English is default" rule — generalised to whichever
 * language is actually flagged `is_default` today.
 */
class StoreLookupValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', LookupValue::class) ?? false;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->idOrFail();

        return [
            'lookup_category_id' => ['required', Rule::exists('lookup_categories', 'id')->where('tenant_id', $tenantId)],
            // Case is deliberately not restricted to upper-case — e.g. GENDER
            // seeds lower-case codes ('male'/'female') to match the existing
            // Student.gender column's own values exactly (see BaseDataSeeder).
            'code' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_]+$/'],
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
            $tenantId = app(TenantContext::class)->idOrFail();
            $categoryId = $this->input('lookup_category_id');
            $code = $this->input('code');

            if ($categoryId && $code
                && LookupValue::query()->where('tenant_id', $tenantId)->where('lookup_category_id', $categoryId)->where('code', $code)->exists()
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

            $defaultLanguage = Language::query()->default()->first();
            if ($defaultLanguage !== null && trim((string) ($translations[$defaultLanguage->code]['name'] ?? '')) === '') {
                $validator->errors()->add("translations.{$defaultLanguage->code}.name", "The name in {$defaultLanguage->name} (the default language) is required.");
            }
        });
    }
}
