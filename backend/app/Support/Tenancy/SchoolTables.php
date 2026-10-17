<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Makes a school table safe to share between schools
 * (docs/backoffice/stage-4-database-placement.md, step 4a):
 *
 *   tenant_id bigint NOT NULL DEFAULT <the connection's school>   — inserts fill it
 *   every unique index becomes unique per school: (tenant_id, …)
 *   ROW LEVEL SECURITY, FORCEd, policy school_isolation:
 *     USING / WITH CHECK (tenant_id = <the connection's school>)
 *
 * "The connection's school" is the session setting app.tenant_id, kept by
 * SchoolConnection. Unset → NULL → no rows match and inserts fail NOT NULL:
 * it fails closed.
 *
 * A NEW school table (a later tenant migration) must call SchoolTables::secure()
 * for itself — SchoolTablesTest fails the build for one that does not.
 */
final class SchoolTables
{
    public const COLUMN = 'tenant_id';

    public const POLICY = 'school_isolation';

    /** The current school, as SQL: NULL when the session has none. */
    public const CURRENT = "NULLIF(current_setting('app.tenant_id', true), '')::bigint";

    /**
     * Every school table on this connection: everything in `public` except the
     * migrations ledger and, where they share a database (tests), the central
     * tables — those created by database/migrations/*.php, never by tenant/.
     *
     * @return list<string>
     */
    public static function all(Connection $db): array
    {
        $central = self::centralTables();

        $tables = array_map(
            fn ($row) => $row->tablename,
            $db->select("select tablename from pg_tables where schemaname = 'public' order by tablename"),
        );

        return array_values(array_filter($tables, fn (string $t) => $t !== 'migrations' && ! in_array($t, $central, true)));
    }

    /** @return list<string> */
    public static function centralTables(): array
    {
        $names = [];
        foreach (glob(database_path('migrations/*.php')) ?: [] as $file) {
            preg_match_all("/Schema::create\\(\\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
            array_push($names, ...$m[1]);
        }

        return array_values(array_unique($names));
    }

    /**
     * A database that belongs to no single school — the shared database, the
     * template, the test database — is migrated with no school set. The only
     * rows it can hold then are defaults that older migrations inserted; they
     * belong to nobody and are removed (SchoolDefaults recreates them per
     * school). Anywhere else, rows with no school set is an error.
     */
    public static function belongsToNoSchool(Connection $db): bool
    {
        return app()->environment('testing')
            || $db->selectOne("select current_setting('app.schoolless', true) as v")->v === '1';
    }

    public static function secure(Connection $db, string $table): void
    {
        $t = self::ident($table);
        $col = self::COLUMN;

        $db->statement("ALTER TABLE {$t} ADD COLUMN IF NOT EXISTS {$col} bigint");
        $db->statement("UPDATE {$t} SET {$col} = ".self::CURRENT." WHERE {$col} IS NULL");

        if (self::belongsToNoSchool($db)) {
            $db->statement("DELETE FROM {$t} WHERE {$col} IS NULL");
        }

        $left = (int) $db->selectOne("select count(*) as c from {$t} where {$col} is null")->c;
        if ($left > 0) {
            throw new RuntimeException(
                "{$table} has {$left} row(s) but no school is set on this connection (app.tenant_id) — "
                .'run the school migrations through TenantProvisioningService, not a bare migrate.'
            );
        }

        $db->statement("ALTER TABLE {$t} ALTER COLUMN {$col} SET DEFAULT ".self::CURRENT);
        $db->statement("ALTER TABLE {$t} ALTER COLUMN {$col} SET NOT NULL");
        $db->statement('CREATE INDEX IF NOT EXISTS '.self::ident($table.'_tenant_id_index')." ON {$t} ({$col})");

        self::scopeUniqueIndexes($db, $table);

        $db->statement("ALTER TABLE {$t} ENABLE ROW LEVEL SECURITY");
        $db->statement("ALTER TABLE {$t} FORCE ROW LEVEL SECURITY");
        $db->statement('DROP POLICY IF EXISTS '.self::POLICY." ON {$t}");
        $db->statement('CREATE POLICY '.self::POLICY." ON {$t} USING ({$col} = ".self::CURRENT.') WITH CHECK ('.$col.' = '.self::CURRENT.')');
    }

    /** "unique student_code" becomes "unique student_code within a school". */
    private static function scopeUniqueIndexes(Connection $db, string $table): void
    {
        $indexes = $db->select(<<<'SQL'
            select ci.relname as index_name, pg_get_indexdef(i.indexrelid) as def, con.conname as constraint_name
            from pg_index i
            join pg_class t on t.oid = i.indrelid
            join pg_class ci on ci.oid = i.indexrelid
            left join pg_constraint con on con.conindid = i.indexrelid and con.contype = 'u'
            where t.relname = ? and t.relnamespace = 'public'::regnamespace
              and i.indisunique and not i.indisprimary
            SQL, [$table]);

        foreach ($indexes as $index) {
            if (preg_match('/\(\s*"?tenant_id"?\s*[,)]/', $index->def)) {
                continue; // already per school
            }

            $scoped = preg_replace('/ USING (\w+) \(/', ' USING $1 (tenant_id, ', $index->def, 1, $count);
            if ($count !== 1) {
                throw new RuntimeException("Cannot scope unique index {$index->index_name}: {$index->def}");
            }

            if ($index->constraint_name !== null) {
                $db->statement('ALTER TABLE '.self::ident($table).' DROP CONSTRAINT '.self::ident($index->constraint_name));
            } else {
                $db->statement('DROP INDEX '.self::ident($index->index_name));
            }
            $db->statement($scoped);
        }
    }

    /** The app role: may read and write rows, never bypass the policy, never TRUNCATE (which skips RLS). */
    public static function grantAppRole(Connection $db): void
    {
        // A separate server's connection names no role (''): its own login is
        // what FORCEd row-level security binds, and it may not create roles.
        $role = $db->getConfig('app_role') ?? config('tenancy.database.app_role');
        if (! is_string($role) || $role === '') {
            return;
        }

        $r = self::ident($role);
        $literal = $db->getPdo()->quote($role);

        $db->statement("DO \$\$ BEGIN
            IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = {$literal}) THEN
                CREATE ROLE {$r} NOLOGIN NOSUPERUSER NOBYPASSRLS NOCREATEDB NOCREATEROLE;
            END IF;
        END \$\$");
        $db->statement("GRANT USAGE ON SCHEMA public TO {$r}");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$r}");
        $db->statement("GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$r}");
        $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$r}");
        $db->statement("ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$r}");
    }

    private static function ident(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
