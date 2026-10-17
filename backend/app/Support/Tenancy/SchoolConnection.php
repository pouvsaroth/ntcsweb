<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Tells Postgres which school the `tenant` connection is working for — the
 * other half of row-level security (docs/backoffice/stage-4-database-placement.md).
 *
 * Every school table carries `tenant_id` and a FORCEd policy
 * `tenant_id = app.tenant_id`, so whatever runs on this connection — models,
 * raw queries, `unique`/`exists` validation — only ever sees and writes the
 * current school's rows. With no school set, it sees nothing: a forgotten
 * context fails closed instead of leaking.
 *
 * Superusers bypass RLS, and the app logs in as one (the database owner). So
 * on connect the session also drops to `tenancy.database.app_role`
 * (`ntcs_app`: no superuser, no BYPASSRLS) — except while migrations run,
 * which need the owner to create and alter tables ({@see asOwner()}).
 */
final class SchoolConnection
{
    public const NAME = 'tenant';

    private static int $ownerDepth = 0;

    /** Run $callback with the school connection as the database owner (migrations, provisioning). */
    public static function asOwner(Closure $callback): mixed
    {
        self::$ownerDepth++;
        $wasOpen = array_key_exists(self::NAME, DB::getConnections());
        if ($wasOpen) {
            DB::purge(self::NAME);
        }

        try {
            return $callback();
        } finally {
            self::$ownerDepth--;
            // Never hand an owner session back to ordinary code.
            if (array_key_exists(self::NAME, DB::getConnections())) {
                DB::purge(self::NAME);
            }
        }
    }

    public static function isOwnerMode(): bool
    {
        return self::$ownerDepth > 0;
    }

    /** ConnectionEstablished: drop to the app role and say which school. */
    public static function prepare(Connection $connection): void
    {
        if ($connection->getName() !== self::NAME || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        // A connection may name its own role ('' = none: a separate server's
        // login, which FORCEd row-level security binds as it is).
        $role = $connection->getConfig('app_role') ?? config('tenancy.database.app_role');
        if (! self::isOwnerMode() && is_string($role) && $role !== '') {
            $connection->statement('SET ROLE '.self::quoteIdentifier($role));
        }

        self::apply($connection, app(TenantContext::class)->id());

        if (self::$schoolless) {
            $connection->select("select set_config('app.schoolless', '1', false)");
        }
    }

    private static bool $schoolless = false;

    /**
     * Run $callback as the owner on a database that belongs to no single
     * school — the shared database or the template — so a migration there
     * drops the ownerless default rows old migrations insert
     * (SchoolTables::belongsToNoSchool) instead of refusing.
     */
    public static function asSchoolless(Closure $callback): mixed
    {
        self::$schoolless = true;

        try {
            return self::asOwner($callback);
        } finally {
            self::$schoolless = false;
        }
    }

    /** TenantContext changed: if the school connection is already open, follow it. */
    public static function sync(?int $tenantId): void
    {
        $connection = DB::getConnections()[self::NAME] ?? null;

        if ($connection instanceof Connection) {
            self::apply($connection, $tenantId);
        }
    }

    private static function apply(Connection $connection, ?int $tenantId): void
    {
        // Session-level (false), so it outlives each statement; '' means "no school".
        $connection->select("select set_config('app.tenant_id', ?, false)", [$tenantId === null ? '' : (string) $tenantId]);
    }

    private static function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
