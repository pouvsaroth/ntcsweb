<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Language;
use App\Models\LookupValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The admin/translation-management shape (spec section 12): every
 * configured language is present in `translations`, even ones with no
 * saved row yet (as an empty name/description) so the edit form always
 * shows one input per language. Expects `translations.language` eager
 * loaded — see LookupValueController.
 *
 * Deliberately queries Language fresh every render rather than caching it
 * statically on the class: a static cache here would outlive both a single
 * request under Octane and a single test under PHPUnit (which shares one
 * PHP process across tests), silently pointing at stale Language ids once
 * any later test/request creates its own languages. The table is a handful
 * of rows, so the extra query is not worth that fragility.
 *
 * @mixin LookupValue
 */
class LookupValueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $languages = Language::query()->orderBy('sort_order')->get();

        $existing = $this->translations->keyBy('language_id');

        $translations = $languages->mapWithKeys(function (Language $language) use ($existing) {
            $translation = $existing->get($language->id);

            return [$language->code => [
                'name' => $translation?->name ?? '',
                'description' => $translation?->description ?? '',
            ]];
        });

        return [
            'id' => $this->id,
            'code' => $this->code,
            'lookup_category_id' => $this->lookup_category_id,
            'category' => new LookupCategoryResource($this->whenLoaded('category')),
            'is_active' => $this->is_active,
            'sort_order' => $this->sort_order,
            'translations' => $translations,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
