<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\LookupValueTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Display text is data, never an identifier -- see this table's migration
 * docblock. Deliberately does NOT use the Auditable trait: translations are
 * always edited as part of LookupValueService::syncTranslations(), which
 * fires its own explicit, richer audit entry (one row covering every
 * language changed at once) rather than one generic row per language.
 */
#[Fillable(['lookup_value_id', 'language_id', 'name', 'description'])]
class LookupValueTranslation extends Model
{
    /** @use HasFactory<LookupValueTranslationFactory> */
    use HasFactory;

    public function lookupValue(): BelongsTo
    {
        return $this->belongsTo(LookupValue::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
