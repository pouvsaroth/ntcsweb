<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. What a book teaches, scoped to exactly one Academic Program
 * -- e.g. "Office"/"Design" under Computer, "Kindergarten 1" under English --
 * which is what lets the Book form's Category dropdown filter down to just
 * the categories that make sense once a program is picked.
 *
 * @property int $tenant_id
 * @property string $name
 * @property int $academic_program_id
 * @property bool $is_active
 */
#[Fillable(['name', 'academic_program_id', 'is_active'])]
class BookCategory extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<BookCategoryFactory> */
    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
