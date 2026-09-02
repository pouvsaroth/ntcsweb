<?php

declare(strict_types=1);

namespace App\Services\BaseData;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Str;

/**
 * Caches the resolved (already language-fallback-applied) dropdown list for
 * one category+language pair — the hot path every LookupSelect hits.
 *
 * Same versioned-key trick as PermissionRegistry: invalidation is O(1) —
 * bump a tenant-wide version token instead of scanning/deleting individual
 * category+language keys — because a language, category, or value change
 * can affect an unbounded number of already-cached (category, language)
 * combinations at once.
 */
final class LookupCache
{
    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @param  \Closure(): array  $resolve
     */
    public function remember(int $tenantId, string $categoryCode, string $languageCode, \Closure $resolve): array
    {
        $key = $this->key($tenantId, $categoryCode, $languageCode);

        return $this->cache->remember($key, (int) config('cache.lookup_ttl', 3600), $resolve);
    }

    /** Bumping the tenant's version token retires every cached (category, language) pair for that tenant at once. */
    public function invalidateTenant(int $tenantId): void
    {
        $this->cache->forever($this->versionKey($tenantId), (string) Str::uuid());
    }

    private function key(int $tenantId, string $categoryCode, string $languageCode): string
    {
        return sprintf('lookup:%s:t%d:%s:%s', $this->version($tenantId), $tenantId, mb_strtoupper($categoryCode), $languageCode);
    }

    private function version(int $tenantId): string
    {
        return $this->cache->rememberForever($this->versionKey($tenantId), fn () => (string) Str::uuid());
    }

    private function versionKey(int $tenantId): string
    {
        return "lookup:version:t{$tenantId}";
    }
}
