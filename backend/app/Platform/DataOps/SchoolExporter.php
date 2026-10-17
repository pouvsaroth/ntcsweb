<?php

declare(strict_types=1);

namespace App\Platform\DataOps;

use App\Models\Tenant;
use App\Platform\Services\SchoolDatabase;
use App\Support\Tenancy\SchoolConnection;
use App\Support\Tenancy\SchoolTables;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * One school's own rows, whatever its placement — the only way to back up a
 * school in the shared database, and the first half of moving a school (4d).
 *
 * Read through the school's ordinary connection with the school set, so
 * row-level security guarantees the file holds this school's rows and no
 * other's. The file is a zip:
 *
 *   manifest.json        school, placement, when, migrations applied, rows per table
 *   tables/<table>.jsonl one JSON object per row
 */
class SchoolExporter
{
    public const FORMAT_VERSION = 1;

    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly TenantContext $context,
    ) {}

    /** @return array<string, int> rows per table */
    public function export(Tenant $tenant, string $zipPath): array
    {
        $work = sys_get_temp_dir().'/school-export-'.$tenant->slug.'-'.bin2hex(random_bytes(4));
        mkdir($work.'/tables', 0700, true);

        try {
            [$counts, $migrations] = $this->database->within($tenant, fn () => $this->context->runFor($tenant, function () use ($work) {
                $db = DB::connection(SchoolConnection::NAME);
                $counts = [];

                foreach (SchoolTables::all($db) as $table) {
                    $handle = fopen("{$work}/tables/{$table}.jsonl", 'w');
                    $n = 0;
                    // In id order where there is one: a row that points at an
                    // earlier row of its own table (a parent account, say)
                    // comes after it, so an import can insert in file order.
                    $query = $db->table($table);
                    if ($db->getSchemaBuilder()->hasColumn($table, 'id')) {
                        $query->orderBy('id');
                    }
                    foreach ($query->cursor() as $row) {
                        fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
                        $n++;
                    }
                    fclose($handle);
                    $counts[$table] = $n;
                }

                return [$counts, $db->table('migrations')->orderBy('id')->pluck('migration')->all()];
            }));

            file_put_contents($work.'/manifest.json', json_encode([
                'format' => self::FORMAT_VERSION,
                'school' => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name],
                'placement' => $this->database->placementOf($tenant)->mode,
                'exported_at' => now()->toIso8601String(),
                'migrations' => $migrations,
                'tables' => $counts,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            $this->zip($work, $zipPath);

            return $counts;
        } finally {
            $this->remove($work);
        }
    }

    private function zip(string $dir, string $zipPath): void
    {
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot write {$zipPath}.");
        }
        $zip->addFile($dir.'/manifest.json', 'manifest.json');
        foreach (glob($dir.'/tables/*.jsonl') ?: [] as $file) {
            $zip->addFile($file, 'tables/'.basename($file));
        }
        $zip->close();
    }

    private function remove(string $dir): void
    {
        foreach (glob($dir.'/tables/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($dir.'/tables');
        @unlink($dir.'/manifest.json');
        @rmdir($dir);
    }
}
