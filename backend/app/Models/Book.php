<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. Each school manages its own textbook/material catalog.
 *
 * @property int $tenant_id
 * @property string $title
 * @property int|null $academic_program_id The one program this book belongs to -- drives which BookCategory rows make sense for it.
 * @property int|null $book_category_id What the book teaches within its program, e.g. "Office", "Design" -- see BookCategory.
 * @property string $status
 */
#[Fillable(['title', 'author', 'isbn', 'publisher', 'description', 'cover_image', 'academic_program_id', 'book_category_id', 'status'])]
class Book extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<BookFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of the columns' DB defaults — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_book', 'book_id', 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * The one academic program this book belongs to -- e.g. "MS Word" under
     * the Computer program. This is what lets a Course Package's book picker
     * be filtered down to just the books that make sense for its program;
     * see CoursePackage::books().
     */
    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function bookCategory(): BelongsTo
    {
        return $this->belongsTo(BookCategory::class);
    }

    public function coursePackages(): BelongsToMany
    {
        return $this->belongsToMany(CoursePackage::class, 'course_package_book', 'book_id', 'course_package_id')
            ->withPivot(['sort_order', 'is_required'])
            ->orderByPivot('sort_order');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
