<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\TenantHost;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * A school. Platform-wide table — this is the root of tenancy, so it is the one
 * model that is never itself tenant-scoped.
 *
 * Only platform super admins may read or write tenants; see TenantPolicy.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $code
 * @property string $timezone
 * @property string $locale
 * @property string $status
 * @property array|null $settings
 */
#[Fillable(['name', 'slug', 'code', 'logo', 'email', 'phone', 'address', 'timezone', 'locale', 'status', 'settings'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The hostname -> tenant id cache is read on every request, so it has to
        // drop the moment a slug changes or a school is suspended.
        static::saved(fn (self $tenant) => $tenant->flushHostnameCache());
        static::deleted(fn (self $tenant) => $tenant->flushHostnameCache());
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function domains(): HasMany
    {
        return $this->hasMany(TenantDomain::class);
    }

    public function primaryDomain(): HasOne
    {
        return $this->hasOne(TenantDomain::class)->where('is_primary', true);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Read a value out of the school's settings blob.
     *
     * Dot notation, so `$tenant->setting('public_site.hero_title')` works.
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return Arr::get($this->settings ?? [], $key, $default);
    }

    /**
     * The school's static "receive any amount" Bakong KHQR string (e.g. from
     * ACLEDA Toanchet's "My QR" screen) — see App\Support\Billing\Khqr,
     * which turns this into a fixed-amount code per invoice. Null until a
     * school admin sets it under School Settings.
     */
    public function khqrTemplate(): ?string
    {
        return $this->setting('khqr_template');
    }

    /**
     * `logo` stores a bare disk path (see SchoolSettingsController) — this is
     * the one place that turns it into something an `<img>` tag can load.
     */
    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

    /**
     * Absolute filesystem path to the logo, for dompdf (invoice.blade.php)
     * specifically — dompdf's `enable_remote` is off by default, so handing
     * it the HTTP `logoUrl()` silently produces no image at all. Reading the
     * file straight off disk sidesteps that without opting into remote
     * fetches (and the SSRF surface that comes with them) for the rest of
     * the app.
     */
    public function logoPath(): ?string
    {
        if (! $this->logo) {
            return null;
        }

        $path = Storage::disk('public')->path($this->logo);

        return is_file($path) ? $path : null;
    }

    /**
     * The hostname the SPA and outgoing mail should use for this school.
     */
    public function hostname(): string
    {
        return $this->relationLoaded('primaryDomain') && $this->primaryDomain !== null
            ? $this->primaryDomain->hostname
            : $this->slug.'.'.config('tenancy.root_domain');
    }

    /**
     * Prefix for every file this school owns, on any disk.
     *
     * Keeping this in one place is what makes tenant file isolation auditable:
     * nothing else in the codebase builds a tenant path by hand.
     */
    public function storagePath(string ...$segments): string
    {
        return implode('/', ['tenants', $this->getKey(), ...array_map(
            fn (string $segment) => trim($segment, '/'),
            $segments
        )]);
    }

    /**
     * Namespace a cache key to this school.
     *
     * Every cached value that contains tenant data must go through here —
     * a shared key across schools is a data leak, not just a stale read.
     */
    public function cacheKey(string ...$segments): string
    {
        return implode(':', ['tenant', $this->getKey(), ...$segments]);
    }

    private function flushHostnameCache(): void
    {
        $host = app(TenantHost::class);
        $cache = Cache::store(config('tenancy.cache.store'));

        $hostnames = [
            $this->slug.'.'.config('tenancy.root_domain'),
            ...($this->getOriginal('slug') ? [$this->getOriginal('slug').'.'.config('tenancy.root_domain')] : []),
            ...$this->domains()->pluck('hostname')->all(),
        ];

        foreach (array_unique($hostnames) as $hostname) {
            if ($normalised = $host->normalise($hostname)) {
                $cache->forget($host->cacheKey($normalised));
            }
        }
    }
}
