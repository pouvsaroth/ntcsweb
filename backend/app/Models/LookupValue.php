<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LookupValueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One selectable option within a Lookup Category (e.g. GENDER -> MALE). The
 * `code` is stable and unique within its category+tenant; every
 * human-readable name/description lives in `translations` instead (see that
 * model) -- this row itself carries no display text.
 */
#[Fillable(['lookup_category_id', 'code', 'is_active', 'sort_order'])]
class LookupValue extends Model
{
    /** @use HasFactory<LookupValueFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LookupCategory::class, 'lookup_category_id');
    }

    public function translations(): HasMany
    {
        return $this->hasMany(LookupValueTranslation::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * The display name for a given language, with fallback: requested
     * language -> the platform default language -> whatever translation
     * exists first -> the raw code as a last resort. A dropdown must never
     * render an empty label just because one translation is missing.
     *
     * Expects `translations` (and, ideally, `translations.language`) to
     * already be eager-loaded — this never issues its own query, to keep a
     * list of N values from firing N lookups.
     *
     * @param  \Illuminate\Support\Collection<int, LookupValueTranslation>|null  $translations
     */
    public function resolvedName(?Language $requestedLanguage, ?Language $defaultLanguage, $translations = null): string
    {
        $pool = $translations ?? $this->translations;

        if ($requestedLanguage !== null) {
            $match = $pool->firstWhere('language_id', $requestedLanguage->id);
            if ($match !== null && $match->name !== '') {
                return $match->name;
            }
        }

        if ($defaultLanguage !== null && $defaultLanguage->id !== $requestedLanguage?->id) {
            $match = $pool->firstWhere('language_id', $defaultLanguage->id);
            if ($match !== null && $match->name !== '') {
                return $match->name;
            }
        }

        $first = $pool->first();

        return $first?->name ?? $this->code;
    }

    public function auditModule(): string
    {
        return 'Base Data';
    }

    /**
     * Deliberately just the code, never `$this->translations->first()` —
     * this fires synchronously inside the Auditable trait's created/updated
     * model events, before translations have necessarily been attached or
     * eager-loaded, and preventLazyLoading() would throw on an unloaded
     * relation access at that point.
     */
    public function auditDisplayName(): string
    {
        return $this->code;
    }
}
