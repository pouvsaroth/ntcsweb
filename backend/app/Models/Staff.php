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
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Non-teaching personnel (Accountant, HR, Librarian, IT Officer, ...).
 * Teacher remains its own dedicated model/table — this is not a replacement
 * for it, just the equivalent for everyone else.
 *
 * `user_id` is nullable at the schema level (mirrors Teacher — see the
 * migration), but in practice StaffController::store() always sets it in the
 * same transaction it creates the row in, deriving the role from
 * `position.role` rather than ever accepting one from the request. See
 * Fillable: `user_id` is deliberately absent, so a client can never point a
 * Staff row at an arbitrary existing user by shape of the request body alone.
 *
 * @property int $tenant_id
 * @property int|null $user_id
 * @property int $position_id
 * @property string $employee_code
 * @property string $name
 * @property string $status
 */
#[Fillable(['position_id', 'employee_code', 'name', 'email', 'phone', 'hire_date', 'status'])]
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
        ];
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
        return $this->employee_code ?: $this->name;
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
