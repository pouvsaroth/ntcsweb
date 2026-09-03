<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Audit\AuditAction;
use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Every non-student personnel record — teaching and non-teaching alike
 * (Teacher, Accountant, HR, Librarian, IT Officer, ...). A "teacher" is just
 * a Staff member whose Position is named "Teacher" (see
 * TeacherPositionSeeder) — SchoolClass::teacher() belongs-to's this model
 * directly, filtered to that position at the request-validation layer
 * (StoreSchoolClassRequest).
 *
 * `user_id` is nullable at the schema level, but in practice
 * StaffController::store() always sets it in the
 * same transaction it creates the row in, deriving the role from
 * `position.role` rather than ever accepting one from the request. See
 * Fillable: `user_id` is deliberately absent, so a client can never point a
 * Staff row at an arbitrary existing user by shape of the request body alone.
 *
 * Field shape (name split, structured address, social contacts, ID/photo)
 * deliberately mirrors Student's legacy-migration fields — see the migration
 * that introduced these columns for the exact correspondence. `photo_path`,
 * `national_id_photo_path`, and `profile_color` are also excluded from
 * Fillable for the same reason `user_id` is: they are system-set only, via
 * forceFill in StaffController, never accepted from a request body.
 *
 * @property int $tenant_id
 * @property int|null $user_id
 * @property int $position_id
 * @property string $employee_code
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $photo_path
 * @property string $status
 */
#[Fillable([
    'position_id', 'employee_code', 'first_name', 'last_name', 'other_name',
    'gender', 'date_of_birth', 'birth_place', 'national_id',
    'email', 'phone', 'house_no', 'street_no', 'village_code',
    'facebook', 'telegram', 'other_contact', 'hire_date', 'status',
])]
class Staff extends Model
{
    use Auditable, BelongsToTenant, HasFactory, SoftDeletes;

    /** @use HasFactory<StaffFactory> */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'staff';

    /**
     * `user_id` is excluded from $fillable (see the class docblock), so a
     * plain `fill()` — which is what a factory's constructor call and every
     * mass-assignment path use — would trip Model::shouldBeStrict()'s
     * mass-assignment guard for it. Declaring the PHP-level default here,
     * the same trick Teacher/Student use for their `status` default, gives
     * every fresh instance a real (null) value without going through fill()
     * at all; StaffController::store() sets the real one via forceFill.
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
        'user_id' => null,
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'date_of_birth' => 'date',
        ];
    }

    public function fullName(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path !== null ? Storage::disk('public')->url($this->photo_path) : null;
    }

    public function nationalIdPhotoUrl(): ?string
    {
        return $this->national_id_photo_path !== null ? Storage::disk('public')->url($this->national_id_photo_path) : null;
    }

    protected static function booted(): void
    {
        // Mirrors Student: a soft-deleted staff member still holds both
        // files (recoverable); only a real, permanent removal takes them too.
        static::forceDeleted(function (self $staff) {
            if ($staff->photo_path !== null) {
                Storage::disk('public')->delete($staff->photo_path);
            }

            if ($staff->national_id_photo_path !== null) {
                Storage::disk('public')->delete($staff->national_id_photo_path);
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /**
     * Classes this staff member teaches — meaningful only when their
     * Position is "Teacher" (see TeacherPositionSeeder), same as
     * SchoolClass::teacher().
     */
    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class, 'teacher_id');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function auditModule(): string
    {
        return 'Staff';
    }

    public function auditDisplayName(): string
    {
        return $this->employee_code ?: $this->fullName();
    }

    /**
     * @return array<string, callable(mixed): (string|null)>
     */
    protected function auditLabels(): array
    {
        return [
            'position_id' => fn (?int $id) => $id !== null ? Position::query()->find($id)?->name : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $dirty
     */
    protected function auditActionForDirty(array $dirty): string
    {
        return match (true) {
            array_key_exists('position_id', $dirty) => AuditAction::POSITION_CHANGE,
            array_key_exists('status', $dirty) => AuditAction::STATUS_CHANGE,
            default => AuditAction::UPDATE,
        };
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    protected function auditDescriptionForChange(string $action, array $old, array $new): ?string
    {
        return match ($action) {
            AuditAction::POSITION_CHANGE => "Changed staff position from {$old['position_label']} to {$new['position_label']}",
            AuditAction::STATUS_CHANGE => "Changed staff {$this->auditDisplayName()} status from {$old['status']} to {$new['status']}",
            default => null,
        };
    }
}
