<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Platform\Support\PlatformPermissions;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * A back-office account — one of us, not a school's user. Signs in through
 * the `staff` guard only (config/auth.php); the school login never looks at
 * this table, and this table never holds a tenant_id.
 */
class PlatformStaff extends Authenticatable
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_DISABLED = 'disabled';

    protected $table = 'bo_staff';

    protected $fillable = ['name', 'email'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_last_step' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /** Central, always — see User::getConnectionName() for why this is never left implicit. */
    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(PlatformRole::class, 'bo_role_staff', 'staff_id', 'role_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    public function isOwner(): bool
    {
        return $this->roles->contains(fn (PlatformRole $role) => $role->is_owner);
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->isOwner()) {
            return PlatformPermissions::keys();
        }

        return $this->roles
            ->flatMap(fn (PlatformRole $role) => $role->grantedPermissions())
            ->unique()
            ->values()
            ->all();
    }

    public function hasPermission(string $key): bool
    {
        return in_array($key, $this->permissions(), true);
    }
}
