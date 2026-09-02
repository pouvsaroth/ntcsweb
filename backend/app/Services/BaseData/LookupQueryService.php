<?php

declare(strict_types=1);

namespace App\Services\BaseData;

use App\Models\Language;
use App\Models\LookupCategory;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;

/**
 * The read side any module (Student form, Book form, ...) actually calls:
 * "give me GENDER's active values in the current language." Every response
 * is cached per (tenant, category, language) — see LookupCache.
 */
final class LookupQueryService
{
    public function __construct(
        private readonly LookupCache $cache,
        private readonly TenantContext $context,
    ) {}

    /**
     * @return Collection<int, LookupCategory>
     */
    public function categories(): Collection
    {
        return LookupCategory::query()->active()->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Resolved {id, code, name} rows for one category, in the requested (or
     * fallback) language. Returns [] for an unknown/inactive category code
     * rather than throwing — a dropdown with nothing configured yet should
     * render empty, not 404 a whole form.
     *
     * @return list<array{id:int, code:string, name:string}>
     */
    public function values(string $categoryCode, ?string $requestedLanguageCode): array
    {
        $tenantId = $this->context->idOrFail();
        $defaultLanguage = Language::query()->active()->default()->first();
        $requestedLanguage = $requestedLanguageCode !== null
            ? Language::query()->active()->where('code', $requestedLanguageCode)->first()
            : null;
        $effectiveLanguageCode = $requestedLanguage?->code ?? $defaultLanguage?->code ?? 'en';

        return $this->cache->remember($tenantId, $categoryCode, $effectiveLanguageCode, function () use ($categoryCode, $requestedLanguage, $defaultLanguage) {
            $category = LookupCategory::query()->active()->where('code', mb_strtoupper($categoryCode))->first();

            if ($category === null) {
                return [];
            }

            return $category->values()
                ->active()
                ->with('translations')
                ->orderBy('sort_order')
                ->orderBy('code')
                ->get()
                ->map(fn ($value) => [
                    'id' => $value->id,
                    'code' => $value->code,
                    'name' => $value->resolvedName($requestedLanguage, $defaultLanguage),
                ])
                ->all();
        });
    }
}
