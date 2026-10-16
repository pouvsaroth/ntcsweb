<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Platform\Support\PlatformPermissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A named set of platform.* permissions. The one `is_owner` role holds every
 * key — computed, not stored, so a permission added to the catalogue later is
 * an Owner's without a migration — and can be neither edited nor deleted.
 */
class PlatformRole extends Model
{
    protected $table = 'bo_roles';

    protected $fillable = ['name', 'description', 'permissions'];

    protected function casts(): array
    {
        return [
            'is_owner' => 'boolean',
            'permissions' => 'array',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(PlatformStaff::class, 'bo_role_staff', 'role_id', 'staff_id');
    }

    /**
     * Stored keys that are still in the catalogue — a key removed from the
     * code stops granting anything even before anyone edits the role.
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        $catalogue = PlatformPermissions::keys();

        if ($this->is_owner) {
            return $catalogue;
        }

        return array_values(array_intersect($this->permissions ?? [], $catalogue));
    }
}
