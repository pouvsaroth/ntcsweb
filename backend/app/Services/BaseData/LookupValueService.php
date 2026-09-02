<?php

declare(strict_types=1);

namespace App\Services\BaseData;

use App\Models\Language;
use App\Models\LookupValue;
use App\Models\LookupValueTranslation;
use App\Models\User;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one place a LookupValue's translations are written. A create/update
 * payload carries `translations` keyed by language code (e.g.
 * `['en' => ['name' => 'Male'], 'km' => ['name' => 'ប្រុស']]`) — this is
 * what lets the admin form edit every language in one screen (see the
 * spec's own suggested UI) instead of a separate translation-per-row CRUD.
 *
 * Every write here invalidates that tenant's whole lookup cache — see
 * LookupCache's own docblock for why a single changed value can't just
 * evict its own key.
 */
final class LookupValueService
{
    public function __construct(
        private readonly LookupCache $cache,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{lookup_category_id:int, code:string, is_active?:bool, sort_order?:int, translations?:array<string,array{name?:string|null,description?:string|null}>}  $data
     */
    public function create(array $data, User $actor): LookupValue
    {
        return DB::transaction(function () use ($data, $actor) {
            $translations = $data['translations'] ?? [];
            unset($data['translations']);

            $value = LookupValue::query()->create($data);
            $this->syncTranslations($value, $translations, $actor);
            $this->cache->invalidateTenant($value->tenant_id);

            return $value->load(['category', 'translations.language']);
        });
    }

    /**
     * @param  array{lookup_category_id?:int, code?:string, is_active?:bool, sort_order?:int, translations?:array<string,array{name?:string|null,description?:string|null}>}  $data
     */
    public function update(LookupValue $value, array $data, User $actor): LookupValue
    {
        return DB::transaction(function () use ($value, $data, $actor) {
            $translations = $data['translations'] ?? null;
            unset($data['translations']);

            $value->update($data);

            if ($translations !== null) {
                $this->syncTranslations($value, $translations, $actor);
            }

            $this->cache->invalidateTenant($value->tenant_id);

            return $value->load(['category', 'translations.language']);
        });
    }

    public function delete(LookupValue $value): void
    {
        $value->delete();
        $this->cache->invalidateTenant($value->tenant_id);
    }

    /**
     * @param  array<string,array{name?:string|null,description?:string|null}>  $translations
     */
    private function syncTranslations(LookupValue $value, array $translations, User $actor): void
    {
        if ($translations === []) {
            return;
        }

        $languagesByCode = Language::query()->whereIn('code', array_keys($translations))->get()->keyBy('code');
        $changedLanguageCodes = [];

        foreach ($translations as $languageCode => $fields) {
            $language = $languagesByCode->get($languageCode);
            $name = trim((string) ($fields['name'] ?? ''));

            // A blank name means "no translation for this language yet" —
            // nothing to persist, not an empty row to create.
            if ($language === null || $name === '') {
                continue;
            }

            LookupValueTranslation::query()->updateOrCreate(
                ['lookup_value_id' => $value->getKey(), 'language_id' => $language->getKey()],
                ['name' => $name, 'description' => $fields['description'] ?? null],
            );

            $changedLanguageCodes[] = $languageCode;
        }

        if ($changedLanguageCodes === []) {
            return;
        }

        $this->audit->log(
            AuditAction::TRANSLATION_UPDATED,
            'Base Data',
            $value,
            new: ['languages' => $changedLanguageCodes],
            description: "Updated {$value->code} translations (".implode(', ', $changedLanguageCodes).')',
            actor: $actor,
        );
    }
}
