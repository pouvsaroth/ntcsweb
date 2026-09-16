<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PromotionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. One banner image on the public site's Promotion page. Same
 * shape as GalleryImage — see that model's docblock.
 *
 * @property string $image_path
 * @property string $status
 */
#[Fillable(['image_path', 'title', 'sort_order', 'status'])]
class Promotion extends Model
{
    use HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<PromotionFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A soft-deleted row still holds the file (recoverable); a real,
        // permanent removal is the only time the file itself should go too.
        static::forceDeleted(function (self $promotion) {
            Storage::disk('public')->delete($promotion->image_path);
        });
    }

    public function imageUrl(): string
    {
        return Storage::disk('public')->url($this->image_path);
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
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }
}
