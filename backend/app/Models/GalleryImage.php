<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GalleryImageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. One photo in the public site's Gallery page.
 *
 * @property int $tenant_id
 * @property string $image_path
 * @property string $status
 */
#[Fillable(['image_path', 'caption', 'sort_order', 'status'])]
class GalleryImage extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<GalleryImageFactory> */
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
        static::forceDeleted(function (self $image) {
            Storage::disk('public')->delete($image->image_path);
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
