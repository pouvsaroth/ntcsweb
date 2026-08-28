<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Authorization\PermissionRegistry;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Tenant-owned, with one exception: tenant_id IS NULL marks a platform role,
 * currently only "super-admin".
 *
 * Deliberately NOT using BelongsToTenant. That trait fails closed when no
 * tenant is in context, and roles have to be readable during authentication —
 * before a tenant exists — and by platform super admins whose tenant is NULL.
 * Scoping is instead explicit through {@see scopeVisibleTo()}.
 *
 * tenant_id and is_system are deliberately absent from $fillable, the same way
 * User excludes tenant_id: both are set by trusted internal code (the seeder,
 * a future RoleService) via forceFill, never from request input. A school
 * admin creating a role must never be able to mark it is_system or attach it
 * to another tenant by shape of the request body alone.
 *
 * @property int|null $tenant_id
 * @property string $slug
 * @property int $level
 * @property bool $is_system
 */
#[Fillable(['name', 'slug', 'description', 'level'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SUPER_ADMIN = 'super-admin';

    public const SCHOOL_ADMIN = 'school-admin';

    public const TEACHER = 'teacher';

    public const STAFF = 'staff';

    public const STUDENT = 'student';

    /**
     * Hierarchy. A user may only manage roles strictly below their own highest
     * level, which stops a School Admin from minting another School Admin's
     * privileges upward or a Teacher from promoting themselves.
     */
    public const LEVELS = [
        self::SUPER_ADMIN => 100,
        self::SCHOOL_ADMIN => 80,
        self::TEACHER => 50,
        self::STAFF => 40,
        self::STUDENT => 10,
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'level' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Any change to a role's permissions changes what its holders can do,
        // so the whole tenant's permission cache is invalidated.
        static::saved(fn (self $role) => app(PermissionRegistry::class)->invalidate($role->tenant_id));
        static::deleted(fn (self $role) => app(PermissionRegistry::class)->invalidate($role->tenant_id));
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * Roles a given user is allowed to see: their own school's, plus platform
     * roles only when they are a super admin.
     *
     * @param  Builder<static>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $query->where('tenant_id', $user->tenant_id);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePlatform(Builder $query): void
    {
        $query->whereNull('tenant_id');
    }

    public function isPlatformRole(): bool
    {
        return $this->tenant_id === null;
    }

    public function isSuperAdmin(): bool
    {
        return $this->slug === self::SUPER_ADMIN && $this->isPlatformRole();
    }
}
