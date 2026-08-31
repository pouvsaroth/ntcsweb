<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasRolesAndPermissions;
use App\Support\Audit\AuditAction;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail as MustVerifyEmailContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\HasApiTokens;

/**
 * Tenant-owned, with tenant_id IS NULL reserved for platform super admins.
 *
 * Deliberately NOT using BelongsToTenant. That trait fails closed when no
 * tenant is in context, and users must be readable during authentication —
 * before any tenant exists — and platform admins legitimately have a NULL
 * tenant, which the trait would reject on write.
 *
 * Scoping is therefore explicit here:
 *   - {@see scopeInTenant()} for tenant-scoped reads (all admin listings)
 *   - {@see scopePlatform()} for super admins
 * and every authenticated request is additionally checked by the
 * EnsureTenantMatchesUser middleware.
 *
 * tenant_id is intentionally absent from $fillable: it is never taken from
 * request input, only set by UserService from the resolved tenant.
 *
 * @property int|null $tenant_id
 * @property string $email
 * @property string|null $phone
 * @property string $status
 */
#[Fillable(['name', 'email', 'phone', 'password', 'status', 'locale', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmailContract
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasApiTokens, HasFactory, HasRolesAndPermissions, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVITED = 'invited';

    public const STATUS_SUSPENDED = 'suspended';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The Student record this account is linked to, if this is a student's
     * own login — used to scope "my invoices" and similar self-service
     * views to the right student. Null for every non-student account.
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Constrain to one school. Every admin-facing user query must go through
     * this or through the tenant relation.
     *
     * @param  Builder<static>  $query
     */
    public function scopeInTenant(Builder $query, Tenant|int|null $tenant): void
    {
        $id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $id === null
            ? $query->whereNull($query->qualifyColumn('tenant_id'))
            : $query->where($query->qualifyColumn('tenant_id'), $id);
    }

    /**
     * Platform staff only — the accounts with no school of their own.
     *
     * @param  Builder<static>  $query
     */
    public function scopePlatform(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('tenant_id'));
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where($query->qualifyColumn('status'), self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function belongsToTenant(Tenant|int|null $tenant): bool
    {
        $id = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->tenant_id === $id;
    }

    public function recordLogin(?string $ip): void
    {
        // Written without touching updated_at: a login is not a profile edit,
        // and this runs on every sign-in.
        $this->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->saveQuietly(['timestamps' => false]);
    }

    /**
     * Stored under `tenants/{id}/avatars` for a school user, or
     * `platform/avatars` for a super admin (tenant_id NULL) — see
     * AuthController::updateProfile(), the only place avatar_path is set.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path !== null ? Storage::disk('public')->url($this->avatar_path) : null;
    }

    public function auditModule(): string
    {
        return 'Users';
    }

    public function auditDisplayName(): string
    {
        return $this->name ?: ($this->email ?? '#'.$this->getKey());
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
            return "Changed user {$this->auditDisplayName()} status from {$old['status']} to {$new['status']}";
        }

        return null;
    }
}
