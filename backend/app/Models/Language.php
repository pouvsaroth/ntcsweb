<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\LanguageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Platform-global (no tenant_id) -- every school shares the same language
 * list, the same way provinces/districts/communes/villages are shared. A
 * new language (th, vi, fr...) is one more row here, never a schema change.
 * Exactly one row may have is_default=true, enforced by a partial unique
 * index (see the migration) rather than application code alone.
 */
#[Fillable(['code', 'name', 'native_name', 'is_active', 'is_default', 'sort_order'])]
class Language extends Model
{
    /** @use HasFactory<LanguageFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'is_active' => true,
        'is_default' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    public function lookupValueTranslations(): HasMany
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
     * @param  Builder<static>  $query
     */
    public function scopeDefault(Builder $query): void
    {
        $query->where('is_default', true);
    }

    public function auditModule(): string
    {
        return 'Base Data';
    }

    public function auditDisplayName(): string
    {
        return "{$this->code} - {$this->name}";
    }
}
