<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Data ops > Tables: look at one table of one of our databases, filter its
 * rows, change one row, or clear the table. Table and column names are only
 * ever taken from the database's own catalog (never from the request as SQL);
 * values are always bound.
 *
 * Secret-looking columns (passwords, tokens, 2-step keys) are shown masked
 * and can't be filtered on or changed.
 */
final class TableBrowser
{
    public const OPERATORS = ['eq', 'ne', 'gt', 'gte', 'lt', 'lte', 'contains', 'not_contains', 'starts', 'ends', 'null', 'not_null'];

    public const MASK = '••••';

    private const SECRET_PATTERN = '/(password|secret|token|recovery_codes|api_key|private_key)/i';

    public function __construct(private readonly PlatformDatabases $databases) {}

    /**
     * A table's columns, in order.
     *
     * @return list<array{name: string, type: string, nullable: bool, default: ?string, primary: bool, secret: bool, binary: bool, editable: bool}>
     */
    public function columns(string $database, string $table): array
    {
        return $this->databases->using($database, fn (Connection $c) => $this->columnsOn($c, $table));
    }

    /**
     * One page of rows, every value as text (null stays null).
     *
     * @param  list<array{column: string, operator: string, value?: ?string}>  $conditions
     * @return array{columns: list<array<string, mixed>>, rows: list<array<string, ?string>>, total: int, page: int, per_page: int}
     */
    public function rows(string $database, string $table, array $conditions, ?string $sort, string $direction, int $page, int $perPage): array
    {
        return $this->databases->using($database, function (Connection $c) use ($table, $conditions, $sort, $direction, $page, $perPage) {
            $columns = $this->columnsOn($c, $table);
            $byName = array_column($columns, null, 'name');
            [$where, $bindings] = $this->where($conditions, $byName);

            $order = $sort !== null && isset($byName[$sort]) ? [$sort] : array_column(array_filter($columns, fn ($col) => $col['primary']), 'name');
            $orderSql = $order === [] ? '' : ' order by '.implode(', ', array_map(fn ($n) => $this->quote($n).($direction === 'desc' ? ' desc' : ' asc'), $order));

            try {
                $total = (int) $c->selectOne('select count(*) as n from '.$this->table($table).$where, $bindings)->n;
                $raw = $c->select(
                    'select '.$this->selectList($columns).' from '.$this->table($table).$where.$orderSql.' limit '.$perPage.' offset '.(($page - 1) * $perPage),
                    $bindings,
                );
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['conditions' => $this->dbMessage($e)]);
            }

            return [
                'columns' => $columns,
                'rows' => array_map(fn ($r) => $this->present((array) $r, $byName), $raw),
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
            ];
        });
    }

    /**
     * Change one row, found by its primary key. Returns the row's old and new
     * values of the changed columns (for the audit log) and the row after.
     *
     * @param  array<string, ?string>  $key  primary key column => value
     * @param  array<string, ?string>  $changes  column => new value (null = NULL)
     * @return array{old: array<string, ?string>, new: array<string, ?string>, row: array<string, ?string>}
     */
    public function update(string $database, string $table, array $key, array $changes): array
    {
        return $this->databases->using($database, function (Connection $c) use ($table, $key, $changes) {
            $columns = $this->columnsOn($c, $table);
            $byName = array_column($columns, null, 'name');
            $primary = array_column(array_filter($columns, fn ($col) => $col['primary']), 'name');

            if ($primary === []) {
                throw ValidationException::withMessages(['table' => __('This table has no primary key, so a single row can\'t be changed safely.')]);
            }
            if (array_diff($primary, array_keys($key)) !== []) {
                throw ValidationException::withMessages(['key' => __('Give every primary key column: :cols', ['cols' => implode(', ', $primary)])]);
            }
            foreach (array_keys($changes) as $name) {
                if (! isset($byName[$name])) {
                    throw ValidationException::withMessages(['changes' => __('Unknown column: :col', ['col' => $name])]);
                }
                if (! $byName[$name]['editable']) {
                    throw ValidationException::withMessages(['changes' => __('This column can\'t be changed here: :col', ['col' => $name])]);
                }
                if ($changes[$name] === null && ! $byName[$name]['nullable']) {
                    throw ValidationException::withMessages(['changes' => __(':col can\'t be empty (NULL).', ['col' => $name])]);
                }
            }

            $keyWhere = implode(' and ', array_map(fn ($n) => $this->quote($n).' = ?::'.$byName[$n]['type'], $primary));
            $keyBindings = array_map(fn ($n) => $key[$n], $primary);

            try {
                return $c->transaction(function () use ($c, $table, $columns, $byName, $changes, $keyWhere, $keyBindings) {
                    $before = $c->selectOne('select '.$this->selectList($columns).' from '.$this->table($table).' where '.$keyWhere.' for update', $keyBindings);
                    if ($before === null) {
                        throw new NotFoundHttpException(__('That row no longer exists.'));
                    }
                    $before = (array) $before;

                    if ($changes !== []) {
                        $set = implode(', ', array_map(fn ($n) => $this->quote($n).' = ?::'.$byName[$n]['type'], array_keys($changes)));
                        $c->update('update '.$this->table($table).' set '.$set.' where '.$keyWhere, [...array_values($changes), ...$keyBindings]);
                    }
                    $after = (array) $c->selectOne('select '.$this->selectList($columns).' from '.$this->table($table).' where '.$keyWhere, $keyBindings);

                    $old = [];
                    $new = [];
                    foreach (array_keys($changes) as $name) {
                        if ($before[$name] !== $after[$name]) {
                            $old[$name] = $before[$name];
                            $new[$name] = $after[$name];
                        }
                    }

                    return ['old' => $old, 'new' => $new, 'row' => $this->present($after, $byName)];
                });
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['changes' => $this->dbMessage($e)]);
            }
        });
    }

    /**
     * What clearing $table would touch: the tables it points to (parents) and
     * the tables that point to it (children), each with its row count, and —
     * with cascade — every table TRUNCATE ... CASCADE would also empty.
     *
     * @return array{table: string, rows: int, references: list<array<string, mixed>>, referenced_by: list<array<string, mixed>>, cascade: list<array{table: string, rows: int}>}
     */
    public function relationships(string $database, string $table): array
    {
        return $this->databases->using($database, function (Connection $c) use ($table) {
            $this->assertTable($c, $table);
            $fks = $this->foreignKeys($c);

            $references = array_values(array_filter($fks, fn ($fk) => $fk['from_table'] === $table && $fk['to_table'] !== $table));
            $referencedBy = array_values(array_filter($fks, fn ($fk) => $fk['to_table'] === $table && $fk['from_table'] !== $table));

            $cascade = $this->cascadeSet($table, $fks);
            $counts = $this->counts($c, array_unique([$table, ...array_column($references, 'to_table'), ...array_column($referencedBy, 'from_table'), ...$cascade]));

            $withRows = fn (array $fk, string $side) => $fk + ['rows' => $counts[$fk[$side]] ?? 0];

            return [
                'table' => $table,
                'rows' => $counts[$table] ?? 0,
                'references' => array_map(fn ($fk) => $withRows($fk, 'to_table'), $references),
                'referenced_by' => array_map(fn ($fk) => $withRows($fk, 'from_table'), $referencedBy),
                'cascade' => array_map(fn ($t) => ['table' => $t, 'rows' => $counts[$t] ?? 0], array_values(array_diff($cascade, [$table]))),
            ];
        });
    }

    /**
     * Empty $table (TRUNCATE). Refused when other tables point to it unless
     * $cascade, which empties those too (Postgres's TRUNCATE ... CASCADE).
     *
     * @return list<string> every table emptied
     */
    public function clear(string $database, string $table, bool $cascade, bool $restartIds): array
    {
        return $this->databases->using($database, function (Connection $c) use ($table, $cascade, $restartIds) {
            $this->assertTable($c, $table);
            $set = $this->cascadeSet($table, $this->foreignKeys($c));
            if (count($set) > 1 && ! $cascade) {
                throw ValidationException::withMessages(['cascade' => __('Other tables point to :table. Tick "also clear the tables that point to it" to clear them too.', ['table' => $table])]);
            }

            try {
                $c->statement('truncate table '.$this->table($table).($restartIds ? ' restart identity' : '').($cascade ? ' cascade' : ''));
            } catch (QueryException $e) {
                throw ValidationException::withMessages(['table' => $this->dbMessage($e)]);
            }

            return $set;
        });
    }

    /**
     * $table plus every table that (directly or through others) points to it
     * — what TRUNCATE ... CASCADE empties.
     *
     * @param  list<array<string, mixed>>  $fks
     * @return list<string>
     */
    public function cascadeSet(string $table, array $fks): array
    {
        $set = [$table];
        for ($i = 0; $i < count($set); $i++) {
            foreach ($fks as $fk) {
                if ($fk['to_table'] === $set[$i] && ! in_array($fk['from_table'], $set, true)) {
                    $set[] = $fk['from_table'];
                }
            }
        }

        return $set;
    }

    /**
     * The tables TRUNCATE would empty, read on this database (for a backup
     * made just before clearing).
     *
     * @return list<string>
     */
    public function clearSet(string $database, string $table, bool $cascade): array
    {
        return $cascade
            ? $this->databases->using($database, fn (Connection $c) => $this->cascadeSet($table, $this->foreignKeys($c)))
            : [$table];
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function columnsOn(Connection $c, string $table): array
    {
        $this->assertTable($c, $table);

        $rows = $c->select(
            "select a.attname as name,
                    format_type(a.atttypid, a.atttypmod) as type,
                    t.typname as base,
                    not a.attnotnull as nullable,
                    pg_get_expr(d.adbin, d.adrelid) as \"default\",
                    a.attgenerated <> '' as generated,
                    coalesce(a.attnum = any(i.indkey), false) as \"primary\"
             from pg_attribute a
             join pg_class cl on cl.oid = a.attrelid
             join pg_namespace n on n.oid = cl.relnamespace and n.nspname = 'public'
             join pg_type t on t.oid = a.atttypid
             left join pg_attrdef d on d.adrelid = a.attrelid and d.adnum = a.attnum
             left join pg_index i on i.indrelid = a.attrelid and i.indisprimary
             where cl.relname = ? and a.attnum > 0 and not a.attisdropped
             order by a.attnum",
            [$table],
        );

        return array_map(function ($r) {
            $secret = (bool) preg_match(self::SECRET_PATTERN, $r->name);
            $binary = $r->base === 'bytea';

            return [
                'name' => (string) $r->name,
                'type' => (string) $r->type,
                'nullable' => (bool) $r->nullable,
                'default' => $r->default,
                'primary' => (bool) $r->primary,
                'secret' => $secret,
                'binary' => $binary,
                // The key finds the row, so it isn't changed here.
                'editable' => ! $secret && ! $binary && ! $r->generated && ! $r->primary,
            ];
        }, $rows);
    }

    private function assertTable(Connection $c, string $table): void
    {
        $exists = $c->selectOne(
            "select 1 as ok from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') and c.relname = ?",
            [$table],
        );
        if ($exists === null) {
            throw ValidationException::withMessages(['table' => __('That table does not exist in this database.')]);
        }
    }

    /** @return list<array{name: string, from_table: string, from_columns: list<string>, to_table: string, to_columns: list<string>, on_delete: string}> */
    private function foreignKeys(Connection $c): array
    {
        $actions = ['a' => 'no action', 'r' => 'restrict', 'c' => 'cascade', 'n' => 'set null', 'd' => 'set default'];
        $rows = $c->select(
            "select con.conname as name, src.relname as from_table, dst.relname as to_table, con.confdeltype as on_delete,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(con.conkey) with ordinality k(attnum, ord) join pg_attribute a on a.attrelid = con.conrelid and a.attnum = k.attnum) as from_columns,
                    (select string_agg(a.attname, ',' order by k.ord) from unnest(con.confkey) with ordinality k(attnum, ord) join pg_attribute a on a.attrelid = con.confrelid and a.attnum = k.attnum) as to_columns
             from pg_constraint con
             join pg_class src on src.oid = con.conrelid
             join pg_class dst on dst.oid = con.confrelid
             join pg_namespace n on n.oid = src.relnamespace and n.nspname = 'public'
             where con.contype = 'f'
             order by src.relname, con.conname"
        );

        return array_map(fn ($r) => [
            'name' => (string) $r->name,
            'from_table' => (string) $r->from_table,
            'from_columns' => explode(',', (string) $r->from_columns),
            'to_table' => (string) $r->to_table,
            'to_columns' => explode(',', (string) $r->to_columns),
            'on_delete' => $actions[$r->on_delete] ?? (string) $r->on_delete,
        ], $rows);
    }

    /**
     * Exact row counts (count(*)) — these decide whether to clear.
     *
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function counts(Connection $c, array $tables): array
    {
        $out = [];
        foreach ($tables as $t) {
            $out[$t] = (int) $c->selectOne('select count(*) as n from '.$this->table($t))->n;
        }

        return $out;
    }

    /**
     * @param  list<array{column: string, operator: string, value?: ?string}>  $conditions
     * @param  array<string, array<string, mixed>>  $byName
     * @return array{0: string, 1: list<mixed>}
     */
    private function where(array $conditions, array $byName): array
    {
        $parts = [];
        $bindings = [];

        foreach ($conditions as $i => $cond) {
            $col = $byName[$cond['column']] ?? null;
            if ($col === null) {
                throw ValidationException::withMessages(["conditions.$i.column" => __('Unknown column: :col', ['col' => $cond['column']])]);
            }
            if ($col['secret'] || $col['binary']) {
                throw ValidationException::withMessages(["conditions.$i.column" => __('This column can\'t be filtered on: :col', ['col' => $cond['column']])]);
            }
            $q = $this->quote($col['name']);
            $value = $cond['value'] ?? null;
            $like = fn (string $v) => str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $v);

            switch ($cond['operator']) {
                case 'null': $parts[] = "$q is null";
                    break;
                case 'not_null': $parts[] = "$q is not null";
                    break;
                case 'contains': $parts[] = "$q::text ilike ?";
                    $bindings[] = '%'.$like((string) $value).'%';
                    break;
                case 'not_contains': $parts[] = "($q is null or $q::text not ilike ?)";
                    $bindings[] = '%'.$like((string) $value).'%';
                    break;
                case 'starts': $parts[] = "$q::text ilike ?";
                    $bindings[] = $like((string) $value).'%';
                    break;
                case 'ends': $parts[] = "$q::text ilike ?";
                    $bindings[] = '%'.$like((string) $value);
                    break;
                default:
                    $op = ['eq' => '=', 'ne' => '<>', 'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<='][$cond['operator']] ?? null;
                    if ($op === null) {
                        throw ValidationException::withMessages(["conditions.$i.operator" => __('Unknown operator.')]);
                    }
                    $parts[] = $cond['operator'] === 'ne' ? "($q is null or $q $op ?::{$col['type']})" : "$q $op ?::{$col['type']}";
                    $bindings[] = $value;
            }
        }

        return [$parts === [] ? '' : ' where '.implode(' and ', $parts), $bindings];
    }

    /** Every column as text; binary as its size, so the page never carries raw bytes. */
    private function selectList(array $columns): string
    {
        return implode(', ', array_map(fn ($col) => $col['binary']
            ? 'octet_length('.$this->quote($col['name']).')::text as '.$this->quote($col['name'])
            : $this->quote($col['name']).'::text as '.$this->quote($col['name']), $columns));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $byName
     * @return array<string, ?string>
     */
    private function present(array $row, array $byName): array
    {
        foreach ($row as $name => $value) {
            if ($value !== null && ($byName[$name]['secret'] ?? false)) {
                $row[$name] = self::MASK;
            }
        }

        return $row;
    }

    private function table(string $table): string
    {
        return 'public.'.$this->quote($table);
    }

    private function quote(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    /** Postgres's own message, first line, without the SQL. */
    private function dbMessage(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        $message = preg_replace('/^SQLSTATE\[\w+\]: [^:]+: \d+ /', '', $message) ?? $message;

        return trim(strtok($message, "\n") ?: $message);
    }
}
