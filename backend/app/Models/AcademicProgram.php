<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AcademicProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The academic curriculum area a school teaches — English, Chinese,
 * Computer, and anything an administrator adds later. Deliberately NOT
 * named `Program` — see the migration's docblock: that model already means
 * the public marketing catalog in this app. Unrelated, both stay in the
 * schema side by side.
 */
#[Fillable(['code', 'name', 'description', 'is_active', 'sort_order'])]
class AcademicProgram extends Model
{
    /** @use HasFactory<AcademicProgramFactory> */
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

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'program_book', 'program_id', 'book_id');
    }

    public function coursePackages(): HasMany
    {
        return $this->hasMany(CoursePackage::class, 'academic_program_id');
    }

    public function programOfferings(): HasMany
    {
        return $this->hasMany(ProgramOffering::class, 'academic_program_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'academic_program_id');
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
        return "{$this->code} - {$this->name}";
    }
}
