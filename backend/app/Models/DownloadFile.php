<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DownloadFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tenant-owned. One downloadable file inside a DownloadFolder.
 *
 * @property int $download_folder_id
 * @property string $name
 * @property string $file_path
 * @property string|null $mime_type
 * @property int $size
 */
#[Fillable(['download_folder_id', 'name', 'file_path', 'mime_type', 'size', 'sort_order'])]
class DownloadFile extends Model
{
    /** @use HasFactory<DownloadFileFactory> */
    use HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'sort_order' => 0,
        'size' => 0,
    ];

    protected function casts(): array
    {
        return [
            'download_folder_id' => 'integer',
            'size' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::deleted(function (self $file) {
            Storage::disk('public')->delete($file->file_path);
        });
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DownloadFolder::class, 'download_folder_id');
    }

    public function url(): string
    {
        return Storage::disk('public')->url($this->file_path);
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->file_path, PATHINFO_EXTENSION));
    }

    /** Pictures get a thumbnail on the site; everything else an icon. */
    public function isImage(): bool
    {
        return in_array($this->extension(), ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
    }

    /** `name` plus the stored file's extension, unless `name` already ends with it. */
    public function downloadName(): string
    {
        $extension = $this->extension();
        $name = trim($this->name) !== '' ? $this->name : 'file';

        return $extension !== '' && ! Str::endsWith(strtolower($name), '.'.$extension) ? "{$name}.{$extension}" : $name;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('name')->orderBy('id');
    }
}
