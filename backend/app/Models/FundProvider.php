<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\FundProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant-owned. Fund > Providers: someone who gives money to a fund.
 * `code` (P00001) is given from the id on create.
 */
#[Fillable(['name', 'phone', 'address', 'notes', 'is_active'])]
class FundProvider extends Model
{
    /** @use HasFactory<FundProviderFactory> */
    use Auditable, HasFactory;

    protected $connection = 'tenant';

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (FundProvider $provider) {
            if ($provider->code === null) {
                $provider->forceFill(['code' => sprintf('P%05d', $provider->id)])->saveQuietly();
            }
        });
    }

    /** @return HasMany<FundTransaction, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(FundTransaction::class);
    }

    public function auditModule(): string
    {
        return 'Fund';
    }
}
