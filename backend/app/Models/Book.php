<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Tenant-owned. Each school manages its own textbook/material catalog.
 *
 * @property int $tenant_id
 * @property string $title
 * @property string $status
 */
#[Fillable(['title', 'author', 'isbn', 'publisher', 'description', 'cover_image', 'quantity', 'fee', 'status'])]
class Book extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<BookFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of the columns' DB defaults — see Teacher for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'quantity' => 1,
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            // A default/list fee — what a new enrollment for this book
            // pre-fills, not what an already-enrolled student is charged
            // (that's snapshotted onto Enrollment::$fee instead).
            'fee' => 'decimal:2',
        ];
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_book', 'book_id', 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }
}
