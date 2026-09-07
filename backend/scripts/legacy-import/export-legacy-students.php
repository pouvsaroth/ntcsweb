<?php

declare(strict_types=1);

/**
 * Step 1 of the legacy student migration: dump `t_student` from the old
 * MySQL `schooldb` database to a CSV shaped exactly like what
 * App\Jobs\ProcessStudentImport already expects (see its COLUMN_MAP) — so
 * the same, already-tested import path (php artisan students:import-legacy,
 * next to this script) reads it with no reformatting.
 *
 * Run this with the HOST's PHP (it has pdo_mysql; the app's Docker php
 * container only has pdo_pgsql), from anywhere:
 *
 *   php backend/scripts/legacy-import/export-legacy-students.php \
 *     --host=127.0.0.1 --port=3306 --database=Schooldb --user=root --password= \
 *     --out=backend/storage/app/legacy-imports/t_student_export.csv
 *
 * All options shown above are also the defaults, so a bare invocation
 * (no flags) works against the connection details given for this migration.
 * The output path is deliberately under backend/storage/app/ — that's the
 * php container's /var/www/html/storage/app/ once written, ready for the
 * import command to read with no copying.
 */

/** @return array<string, string> */
function parseOptions(array $argv): array
{
    $defaults = [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'Schooldb',
        'user' => 'root',
        'password' => '',
        'table' => 't_student',
        'out' => __DIR__.'/../../storage/app/legacy-imports/t_student_export.csv',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if (! str_starts_with($arg, '--') || ! str_contains($arg, '=')) {
            fwrite(STDERR, "Ignoring unrecognized argument: {$arg}\n");

            continue;
        }

        [$key, $value] = explode('=', substr($arg, 2), 2);

        if (! array_key_exists($key, $defaults)) {
            fwrite(STDERR, "Ignoring unknown option: --{$key}\n");

            continue;
        }

        $defaults[$key] = $value;
    }

    return $defaults;
}

/**
 * Exact source columns, in this order — matches
 * App\Jobs\ProcessStudentImport::COLUMN_MAP keys (case-insensitive there, so
 * this exact casing isn't required, but it documents the mapping clearly).
 * PKID, Photo, created_at, updated_at are deliberately left out: PKID is a
 * source-only surrogate key, Photo points at a path on the old system's disk
 * (no image bytes travel through a CSV), and the timestamps are stamped
 * fresh by the import step.
 */
const COLUMNS = [
    'StudentID', 'FirstName', 'LastName', 'EnglishName', 'Gender', 'BirthDate',
    'HouseNo', 'StreetNo', 'VillageCode', 'OtherAddress',
    'StudentPhone', 'StudentEmail', 'StudentFacebook', 'StudentTelegram',
];

function main(array $argv): int
{
    $opts = parseOptions($argv);

    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $opts['host'], $opts['port'], $opts['database']);

    try {
        $pdo = new PDO($dsn, $opts['user'], $opts['password'] !== '' ? $opts['password'] : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        fwrite(STDERR, "Could not connect to MySQL: {$e->getMessage()}\n");

        return 1;
    }

    $columnList = implode(', ', array_map(fn (string $c) => "`{$c}`", COLUMNS));
    $table = $opts['table'];
    $statement = $pdo->query("SELECT {$columnList} FROM `{$table}` ORDER BY `PKID`");

    $outPath = $opts['out'];
    @mkdir(dirname($outPath), 0755, true);

    $handle = fopen($outPath, 'w');
    if ($handle === false) {
        fwrite(STDERR, "Could not open {$outPath} for writing.\n");

        return 1;
    }

    fputcsv($handle, COLUMNS);

    $rowCount = 0;
    while (($row = $statement->fetch()) !== false) {
        fputcsv($handle, $row);
        $rowCount++;
    }

    fclose($handle);

    fwrite(STDOUT, "Wrote {$rowCount} rows from `{$opts['database']}`.`{$table}` to {$outPath}\n");

    return 0;
}

exit(main($argv));
