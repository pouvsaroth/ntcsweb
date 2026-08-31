<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ProgramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. One course/program in the public marketing catalog — what a
 * visitor browses on the homepage's "Popular Programs" section and the full
 * `/programs` page. Not to be confused with `SchoolClass`, which is an
 * actual scheduled teaching group.
 *
 * @property int $tenant_id
 * @property string $title
 * @property string $level
 * @property string $status
 */
#[Fillable([
    'title', 'subtitle', 'category', 'level', 'duration_label', 'description',
    'image_path', 'is_featured', 'sort_order', 'status',
])]
class Program extends Model
{
    /** @use HasFactory<ProgramFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const LEVEL_BEGINNER = 'beginner';

    public const LEVEL_INTERMEDIATE = 'intermediate';

    public const LEVEL_ADVANCED = 'advanced';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of each column's DB default — see Teacher for why. */
    protected $attributes = [
        'level' => self::LEVEL_BEGINNER,
        'is_featured' => false,
        'sort_order' => 0,
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'is_featured' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A soft-deleted row still holds the file (recoverable); only a
        // real, permanent removal takes the image with it.
        static::forceDeleted(function (self $program) {
            if ($program->image_path !== null) {
                Storage::disk('public')->delete($program->image_path);
            }
        });
    }

    public function imageUrl(): ?string
    {
        return $this->image_path !== null ? Storage::disk('public')->url($this->image_path) : null;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
