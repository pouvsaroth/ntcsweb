<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Connection;
use RuntimeException;
use ZipArchive;

/**
 * Writes one school's export (SchoolExporter) into another data store — the
 * copy step of moving a school. The target must already be migrated to the
 * same point and hold none of this school's rows.
 *
 * Tables go parents first, following the target's own foreign keys; rows go in
 * the file's id order; each serial sequence is then moved past the highest id,
 * so the school's next new row does not collide with a copied one.
 */
class SchoolImporter
{
    private const CHUNK = 500;

    /**
     * @return array<string, int> rows written per table
     */
    public function import(string $zipPath, Connection $target): array
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open {$zipPath}.");
        }

        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
            $counts = [];

            foreach ($this->parentsFirst($target, array_keys($manifest['tables'])) as $table) {
                $lines = $zip->getFromName("tables/{$table}.jsonl");
                if ($lines === false) {
                    throw new RuntimeException("The export has no file for {$table}.");
                }

                $counts[$table] = 0;
                $chunk = [];
                foreach (explode("\n", $lines) as $line) {
                    if ($line === '') {
                        continue;
                    }
                    $chunk[] = $this->row(json_decode($line, true, flags: JSON_THROW_ON_ERROR));
                    if (count($chunk) === self::CHUNK) {
                        $target->table($table)->insert($chunk);
                        $counts[$table] += count($chunk);
                        $chunk = [];
                    }
                }
                if ($chunk !== []) {
                    $target->table($table)->insert($chunk);
                    $counts[$table] += count($chunk);
                }

                $this->bumpSequence($target, $table);
            }

            return $counts;
        } finally {
            $zip->close();
        }
    }

    /**
     * Tables ordered so every table comes after the tables it references
     * (within the school tables). Self-references are left to row order.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public function parentsFirst(Connection $db, array $tables): array
    {
        $edges = [];
        foreach ($db->select(<<<'SQL'
            select child.relname as child, parent.relname as parent
            from pg_constraint c
            join pg_class child on child.oid = c.conrelid
            join pg_class parent on parent.oid = c.confrelid
            where c.contype = 'f' and child.relnamespace = 'public'::regnamespace
            SQL) as $fk) {
            if ($fk->child !== $fk->parent && in_array($fk->child, $tables, true) && in_array($fk->parent, $tables, true)) {
                $edges[$fk->child][$fk->parent] = true;
            }
        }

        $ordered = [];
        $state = [];
        $visit = function (string $table) use (&$visit, &$ordered, &$state, $edges): void {
            if (($state[$table] ?? null) === 'done') {
                return;
            }
            if (($state[$table] ?? null) === 'visiting') {
                throw new RuntimeException("Foreign keys form a cycle through {$table}; cannot order the copy.");
            }
            $state[$table] = 'visiting';
            foreach (array_keys($edges[$table] ?? []) as $parent) {
                $visit($parent);
            }
            $state[$table] = 'done';
            $ordered[] = $table;
        };

        sort($tables);
        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /** Delete this school's rows children first — removing an old copy, or a half-made one. */
    public function deleteAll(Connection $db, int $tenantId): void
    {
        $tables = array_reverse($this->parentsFirst($db, SchoolTables::all($db)));

        $db->transaction(function () use ($db, $tables, $tenantId) {
            foreach ($tables as $table) {
                $db->table($table)->where(SchoolTables::COLUMN, $tenantId)->delete();
            }
        });
    }

    /** JSON gives nested arrays for json/jsonb columns; the database wants the text back. */
    private function row(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value)) {
                $row[$column] = json_encode($value, JSON_UNESCAPED_UNICODE);
            }
        }

        return $row;
    }

    private function bumpSequence(Connection $db, string $table): void
    {
        // Link tables (class_teachers, …) have no id and no sequence.
        if (! $db->getSchemaBuilder()->hasColumn($table, 'id')) {
            return;
        }

        $sequence = $db->selectOne("select pg_get_serial_sequence(?, 'id') as s", ['public.'.$table])->s ?? null;
        if ($sequence === null) {
            return;
        }

        $db->select("select setval(?, greatest((select coalesce(max(id), 0) from public.\"{$table}\"), (select last_value from {$sequence})), true)", [$sequence]);
    }
}
