<?php

declare(strict_types=1);

namespace App\Platform\Models;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What the back office knows about a school beyond its `tenants` row: who
 * owns it, who created it, how its setup went. One per school, keyed by
 * tenant_id; schools that predate the back office get one on first look
 * (School::for()).
 */
class School extends Model
{
    protected $table = 'bo_schools';

    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'invite_sent_at' => 'datetime',
            'provisioning_attempts' => 'integer',
            'copy_setup_parts' => 'array',
        ];
    }

    public function getConnectionName(): ?string
    {
        return config('tenancy.database.central_connection');
    }

    public static function for(Tenant $tenant): self
    {
        return self::query()->firstOrCreate(
            ['tenant_id' => $tenant->getKey()],
            ['activated_at' => $tenant->isActive() ? $tenant->created_at : null],
        );
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(PlatformStaff::class, 'created_by');
    }

    public function provisioningLogs(): HasMany
    {
        return $this->hasMany(SchoolProvisioningLog::class, 'tenant_id', 'tenant_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(SchoolStatusChange::class, 'tenant_id', 'tenant_id');
    }
}
