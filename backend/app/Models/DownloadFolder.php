<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DownloadFolderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. One folder on the public site's Download page (managed under
 * the admin Upload menu). Two levels at most: a top folder may hold
 * sub-folders, a sub-folder may not. Both may hold files.
 *
 * @property int|null $parent_id
 * @property string $name
 * @property string $status
 */
#[Fillable(['parent_id', 'name', 'sort_order', 'status'])]
class DownloadFolder extends Model
{
    /** @use HasFactory<DownloadFolderFactory> */
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $connection = 'tenant';

    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Deleting a folder takes everything inside with it — each file
        // deleted one by one so DownloadFile::booted() removes it from storage.
        static::deleting(function (self $folder) {
            $folder->children()->get()->each->delete();
            $folder->files()->get()->each->delete();
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function files(): HasMany
    {
        return $this->hasMany(DownloadFile::class);
    }

    public function isTopLevel(): bool
    {
        return $this->parent_id === null;
    }

    /** Shown on the public site — itself active, and so is its parent (if any). */
    public function isPublic(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->parent === null || $this->parent->status === self::STATUS_ACTIVE);
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
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
