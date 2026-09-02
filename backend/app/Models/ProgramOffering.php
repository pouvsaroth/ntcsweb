<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProgramOfferingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A program being offered under a specific study mode for a given academic
 * year — e.g. "English - Full Time - 2026".
 */
#[Fillable(['academic_program_id', 'study_mode_id', 'academic_year_id', 'name', 'status'])]
class ProgramOffering extends Model
{
    /** @use HasFactory<ProgramOfferingFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLOSED = 'closed';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function studyMode(): BelongsTo
    {
        return $this->belongsTo(StudyMode::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'program_offering_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function auditModule(): string
    {
        return 'Academic';
    }

    public function auditDisplayName(): string
    {
        $label = $this->name ?? "{$this->academicProgram?->name} - {$this->studyMode?->name}";

        return $this->academicYear?->name ? "{$label} - {$this->academicYear->name}" : $label;
    }
}
