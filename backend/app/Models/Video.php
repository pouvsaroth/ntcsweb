<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\VideoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. A YouTube video lesson belonging to one CoursePackage — the
 * public "Video Lesson" page groups these by course. See the migration's
 * docblock for why the YouTube id/thumbnail/embed URL are derived from
 * `video_url` rather than stored.
 *
 * @property int $tenant_id
 * @property int $course_package_id
 * @property string $title
 * @property string $video_url
 * @property string|null $thumbnail_path
 * @property int $sort_order
 * @property string $status
 */
#[Fillable(['course_package_id', 'title', 'description', 'video_url', 'thumbnail_path', 'sort_order', 'status'])]
class Video extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<VideoFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

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
        // Mirrors HomeSlide/Student: a soft-deleted video still holds its
        // custom thumbnail (recoverable); only a real, permanent removal
        // takes the file too. The YouTube video itself is never touched —
        // this only ever owns the optional local thumbnail override.
        static::forceDeleted(function (self $video) {
            if ($video->thumbnail_path !== null) {
                Storage::disk('public')->delete($video->thumbnail_path);
            }
        });
    }

    public function coursePackage(): BelongsTo
    {
        return $this->belongsTo(CoursePackage::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * The 11-character id YouTube assigns a video — parsed from whatever
     * shape of URL an admin pasted in (watch?v=, youtu.be/, embed/, shorts/,
     * with or without extra query params). Null for a URL that doesn't look
     * like YouTube at all, which StoreVideoRequest/UpdateVideoRequest never
     * actually allow through, but a defensive null is cheaper than a thrown
     * exception every place this is read.
     */
    public function youtubeId(): ?string
    {
        if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/', $this->video_url, $matches)) {
            return $matches[1];
        }

        return null;
    }

    public function thumbnailUrl(): ?string
    {
        if ($this->thumbnail_path !== null) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        $id = $this->youtubeId();

        return $id !== null ? "https://img.youtube.com/vi/{$id}/hqdefault.jpg" : null;
    }

    public function embedUrl(): ?string
    {
        $id = $this->youtubeId();

        return $id !== null ? "https://www.youtube.com/embed/{$id}" : null;
    }

    public function auditModule(): string
    {
        return 'Videos';
    }

    public function auditDisplayName(): string
    {
        return $this->title;
    }
}
