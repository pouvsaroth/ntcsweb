<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RepairShopFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tenant-owned. An external repair/service provider — configurable, never hard-coded (see AssetRepair). */
#[Fillable(['name', 'contact_person', 'phone', 'email', 'address', 'specialization', 'notes', 'is_active'])]
class RepairShop extends Model
{
    /** @use HasFactory<RepairShopFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(AssetRepair::class);
    }

    public function maintenances(): HasMany
    {
        return $this->hasMany(AssetMaintenance::class);
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
        return 'Assets';
    }
}
