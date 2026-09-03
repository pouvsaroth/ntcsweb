<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\StudyModeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-owned, configurable — Full Time / Part Time and anything a school
 * adds later. See the migration's docblock for why this is a real table
 * rather than a Support enum. Seeded with defaults by
 * StudyModeService::ensureDefaults(), not a fixed set.
 */
#[Fillable(['code', 'name', 'is_active', 'sort_order'])]
class StudyMode extends Model
{
    /** @use HasFactory<StudyModeFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    public const FULL_TIME = 'FULL_TIME';

    public const PART_TIME = 'PART_TIME';

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

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
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
