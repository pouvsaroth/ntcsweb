<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\TenantHost;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * A hostname that maps to a school. Platform-wide table: only super admins
 * manage these, because attaching a hostname to the wrong school would hand
 * that school's traffic to someone else.
 *
 * Not using BelongsToTenant on purpose — hostname resolution has to run before
 * any tenant is in context.
 *
 * @property string $hostname
 * @property string $type
 */
#[Fillable(['tenant_id', 'hostname', 'type', 'is_primary', 'verified_at'])]
class TenantDomain extends Model
{
    public const TYPE_SUBDOMAIN = 'subdomain';

    public const TYPE_CUSTOM = 'custom';

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn (self $domain) => $domain->flushCache());
        static::deleted(fn (self $domain) => $domain->flushCache());
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Hostnames are case-insensitive and portless. Normalising on write means
     * the unique index actually holds and lookups never need LOWER().
     */
    protected function hostname(): Attribute
    {
        return Attribute::set(
            fn (string $value) => app(TenantHost::class)->normalise($value) ?? mb_strtolower(trim($value))
        );
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    private function flushCache(): void
    {
        $host = app(TenantHost::class);
        $cache = Cache::store(config('tenancy.cache.store'));

        foreach (array_filter([$this->hostname, $this->getOriginal('hostname')]) as $hostname) {
            if ($normalised = $host->normalise((string) $hostname)) {
                $cache->forget($host->cacheKey($normalised));
            }
        }
    }
}
