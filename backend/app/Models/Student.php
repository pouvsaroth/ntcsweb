<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Audit\AuditAction;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
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
    use Auditable, HasFactory, SoftDeletes;

    protected $connection = 'tenant';

    /** @use HasFactory<StudentFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_GRADUATED = 'graduated';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_INACTIVE = 'inactive';

    /** A self-registered student awaiting admin approval — see StudentRegistrationService. */
    public const STATUS_PENDING = 'pending';

    /** PHP-level mirror of the column's DB default — see Building for why. */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /**
     * Set by StudentRegistrationService::reject() before the status update,
     * so auditDescriptionForChange() below can fold the reason into the
     * audit entry — same pattern as Enrollment::$auditReason.
     */
    public ?string $auditReason = null;

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

    /**
     * The (deduplicated) staff ids — teachers and assistant teachers alike —
     * across this student's own active enrollments' classes. Used to scope
     * the "which teacher is this about" picker on StudentFeedback to staff
     * who actually teach this student, rather than the whole tenant's staff
     * directory.
     *
     * @return list<int>
     */
    public function teacherIds(): array
    {
        $classIds = $this->enrollments()->active()->pluck('class_id');

        return DB::connection('tenant')->table('class_teachers')
            ->whereIn('class_id', $classIds)
            ->distinct()
            ->pluck('staff_id')
            ->all();
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    /**
     * `village_code` is stored as free text, not a real FK (see the
     * migration that added it), but a custom-keyed belongsTo still lets the
     * admin list eager-load the full province/district/commune/village
     * chain in one round trip instead of resolving it per row.
     */
    public function village(): BelongsTo
    {
        return $this->belongsTo(Village::class, 'village_code', 'code');
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
            $description = "Changed student {$this->auditDisplayName()} status from {$old['status']} to {$new['status']}";

            return $this->auditReason !== null ? "{$description}: {$this->auditReason}" : $description;
        }

        return null;
    }
}
