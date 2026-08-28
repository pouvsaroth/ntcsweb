<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves and caches the effective permission set of a user.
 *
 * A permission check happens many times per request, so the flattened slug list
 * is cached. Invalidation is O(1): each tenant carries a version token in the
 * cache, and that token is part of every user's cache key, so bumping it
 * retires the whole school's entries at once without scanning keys.
 *
 * Cache keys are namespaced by tenant. A key shared across schools would let
 * one school's role changes decide another school's access.
 */
final class PermissionRegistry
{
    /** Per-request memoisation, on top of the shared cache. */
    private array $resolved = [];

    public function __construct(private readonly CacheRepository $cache) {}

    /**
     * @return list<string>
     */
    public function forUser(User $user): array
    {
        $key = $this->userKey($user);

        return $this->resolved[$key] ??= $this->cache->remember(
            $key,
            (int) config('auth.rbac.cache_ttl', 900),
            fn () => $this->load($user),
        );
    }

    public function has(User $user, string $permission): bool
    {
        return in_array($permission, $this->forUser($user), true);
    }

    /**
     * Retire every cached permission set belonging to a school.
     *
     * Called whenever a role, a role's permissions, or a user's role
     * assignments change. Pass null for platform-level roles.
     */
    public function invalidate(?int $tenantId): void
    {
        $this->resolved = [];

        $this->cache->forever($this->versionKey($tenantId), (string) Str::uuid());
    }

    /**
     * @return list<string>
     */
    private function load(User $user): array
    {
        // One join rather than loading roles then permissions: this runs for
        // every user on every cold cache.
        return DB::table('permissions')
            ->join('permission_role', 'permissions.id', '=', 'permission_role.permission_id')
            ->join('role_user', 'permission_role.role_id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->getKey())
            ->distinct()
            ->pluck('permissions.slug')
            ->all();
    }

    private function userKey(User $user): string
    {
        return sprintf(
            'rbac:%s:u%d',
            $this->version($user->tenant_id),
            $user->getKey(),
        );
    }

    private function version(?int $tenantId): string
    {
        return $this->cache->rememberForever(
            $this->versionKey($tenantId),
            fn () => (string) Str::uuid(),
        );
    }

    private function versionKey(?int $tenantId): string
    {
        return 'rbac:version:'.($tenantId === null ? 'platform' : 't'.$tenantId);
    }

    /**
     * Ensure the permissions table matches {@see Permissions::catalog()}.
     *
     * Idempotent, so it is safe to run on every deploy. Permissions that have
     * been removed from the catalog are deleted, which cascades to
     * permission_role and immediately withdraws the capability.
     */
    public function sync(): void
    {
        $now = now();

        $rows = [];

        foreach (Permissions::catalog() as $group => $permissions) {
            foreach ($permissions as $slug => $name) {
                $rows[] = [
                    'slug' => $slug,
                    'name' => $name,
                    'group' => $group,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($rows) {
            Permission::query()->upsert($rows, ['slug'], ['name', 'group', 'updated_at']);

            Permission::query()
                ->whereNotIn('slug', array_column($rows, 'slug'))
                ->delete();
        });

        // Every school's effective set may have changed.
        $this->cache->forever($this->versionKey(null), (string) Str::uuid());

        Role::query()
            ->whereNotNull('tenant_id')
            ->distinct()
            ->pluck('tenant_id')
            ->each(fn ($tenantId) => $this->invalidate((int) $tenantId));
    }
}
