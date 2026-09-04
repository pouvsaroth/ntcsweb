<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\AuditAction;
use Database\Factories\CoursePackageFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * The priced, purchasable registration item a student actually pays for —
 * e.g. "MS Word 2024" at $24 — bundling several Books (each one "a subject a
 * student can take, with a fee" — see Book::coursePackages()) taught
 * together as one class curriculum. See the migration's docblock for the
 * full reasoning, especially why `product_id` exists and why changing
 * `price` here never rewrites an already-issued invoice.
 *
 * Overrides `auditActionForDirty()` to emit a dedicated, richer action when
 * `price` specifically changes (capturing old/new price) — mirrors
 * `Enrollment`'s own existing override for status changes.
 */
#[Fillable([
    'code', 'name', 'academic_program_id', 'description', 'thumbnail_path', 'price',
    'fee_monthly', 'fee_term', 'fee_video', 'fee_monthly_online', 'fee_term_online', 'currency',
    'duration', 'product_id', 'is_active', 'show_on_website', 'show_in_popular', 'show_videos',
])]
class CoursePackage extends Model
{
    /** @use HasFactory<CoursePackageFactory> */
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    public const CURRENCY_USD = 'USD';

    public const CURRENCY_KHR = 'KHR';

    protected $attributes = [
        'price' => 0,
        'currency' => self::CURRENCY_USD,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'fee_monthly' => 'decimal:2',
            'fee_term' => 'decimal:2',
            'fee_video' => 'decimal:2',
            'fee_monthly_online' => 'decimal:2',
            'fee_term_online' => 'decimal:2',
            'is_active' => 'boolean',
            'show_on_website' => 'boolean',
            'show_in_popular' => 'boolean',
            'show_videos' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A soft-deleted package still holds its thumbnail file
        // (recoverable); only a real, permanent removal takes the file too —
        // mirrors HomeSlide's own booted() hook.
        static::forceDeleted(function (self $package) {
            if ($package->thumbnail_path !== null) {
                Storage::disk('public')->delete($package->thumbnail_path);
            }
        });
    }

    public function thumbnailUrl(): ?string
    {
        return $this->thumbnail_path !== null ? Storage::disk('public')->url($this->thumbnail_path) : null;
    }

    public function academicProgram(): BelongsTo
    {
        return $this->belongsTo(AcademicProgram::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class, 'course_package_book', 'course_package_id', 'book_id')
            ->withPivot(['sort_order', 'is_required'])
            ->orderByPivot('sort_order');
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_course_package', 'course_package_id', 'class_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'course_package_id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(Video::class);
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

    /**
     * Auditable is a trait, not this class's real parent (Model) — it has no
     * auditActionForDirty() of its own, so `parent::` here would always
     * throw. Returning AuditAction::UPDATE directly reproduces the trait's
     * own default, same as Enrollment's equivalent override does.
     */
    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('price', $dirty) ? AuditAction::PACKAGE_PRICE_CHANGED : AuditAction::UPDATE;
    }

    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::PACKAGE_PRICE_CHANGED) {
            return "Changed {$this->name} price from \${$old['price']} to \${$new['price']}";
        }

        return null;
    }
}
