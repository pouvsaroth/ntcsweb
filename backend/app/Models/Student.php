<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\AuditAction;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Tenant-owned. Expected to be the highest-volume table in the system —
 * every index here maps to a query pattern the admin UI actually needs, per
 * docs/database.md's indexing rule.
 *
 * Field shape (name split, structured address, social contacts, a photo)
 * deliberately mirrors a legacy system's `t_student` table so importing real
 * records from it is a column-to-column mapping — see the migration that
 * introduced these columns for the exact correspondence.
 *
 * @property int $tenant_id
 * @property string $student_code
 * @property string $first_name
 * @property string $last_name
 * @property string|null $photo_path
 * @property string $status
 */
#[Fillable([
    'user_id', 'student_code', 'first_name', 'last_name', 'english_name',
    'date_of_birth', 'gender', 'email', 'phone',
    'house_no', 'street_no', 'village_code', 'other_address',
    'facebook', 'telegram', 'photo_path',
    'enrollment_date', 'status',
])]
class Student extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<StudentFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_INACTIVE = 'inactive';

    /** PHP-level mirror of the column's DB default — see Teacher for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'enrollment_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Mirrors HomeSlide: a soft-deleted student still holds the photo
        // (recoverable); only a real, permanent removal takes the file too.
        static::forceDeleted(function (self $student) {
            if ($student->photo_path !== null) {
                Storage::disk('public')->delete($student->photo_path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    public function educations(): HasMany
    {
        return $this->hasMany(StudentEducation::class);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path !== null ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function auditModule(): string
    {
        return 'Students';
    }

    public function auditDisplayName(): string
    {
        return $this->student_code ?: $this->fullName();
    }

    /**
     * @param  array<string, mixed>  $dirty
     */
    protected function auditActionForDirty(array $dirty): string
    {
        return array_key_exists('status', $dirty) ? AuditAction::STATUS_CHANGE : AuditAction::UPDATE;
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        if ($action === AuditAction::STATUS_CHANGE) {
            return "Changed student {$this->auditDisplayName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
