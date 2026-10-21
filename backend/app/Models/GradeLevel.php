<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. A grade level — Grade 1 … Grade 12, or any level a school
 * adds — in an education stage. `level_order` puts them in order: the next
 * active level is where students go when they are promoted. See
 * docs/academics/school-setup.md.
 *
 * @property string $code
 * @property string $name
 * @property string|null $stage
 * @property int $level_order
 * @property bool $is_active
 */
#[Fillable(['code', 'name', 'stage', 'level_order', 'is_active'])]
class GradeLevel extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const STAGES = ['kindergarten', 'primary', 'lower_secondary', 'upper_secondary', 'other'];

    protected $attributes = [
        'is_active' => true,
        'level_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'level_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @param Builder<static> $query */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('level_order')->orderBy('id');
    }

    /** @return HasMany<SchoolClass, $this> */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /** The subjects (books) taught at this level, with weekly hours. */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'book_grade_level')->withPivot('hours_per_week')->withTimestamps();
    }

    /** The next active level up — null for the last one. */
    public function next(): ?self
    {
        return self::query()->where('is_active', true)
            ->where(fn ($q) => $q->where('level_order', '>', $this->level_order)
                ->orWhere(fn ($q) => $q->where('level_order', $this->level_order)->where('id', '>', $this->getKey())))
            ->ordered()
            ->first();
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        return $this->name;
    }
}
