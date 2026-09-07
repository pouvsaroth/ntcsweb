<?php

declare(strict_types=1);

/**
 * Export step for the legacy student-guardian migration:
 * t_school_student_guardian -> student_guardians.
 *
 * The legacy table links to a student via `Student_PKID` (t_student.PKID),
 * a surrogate key this platform never imported — `students.student_code`
 * (the natural key, from t_student.StudentID) is what survived the move. So
 * this export joins across that relationship in MySQL, while both sides are
 * still in the same database, and writes the natural key instead:
 *
 *   php backend/scripts/legacy-import/export-legacy-student-guardians.php \
 *     --host=127.0.0.1 --port=3306 --database=Schooldb --user=root --password= \
 *     --out=backend/storage/app/legacy-imports/t_school_student_guardian_export.csv
 *
 * All options shown are also the defaults. See export-legacy-students.php
 * for why the output belongs under backend/storage/app/.
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
        'table' => 't_school_student_guardian',
        'out' => __DIR__.'/../../storage/app/legacy-imports/t_school_student_guardian_export.csv',
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
 * StudentID replaces Student_PKID here — see the file doc comment. Every
 * other column matches App\Console\Commands\ImportLegacyStudentGuardiansCommand::COLUMN_MAP.
 * GuardianType travels as-is (the legacy `int` code): the migration that
 * created `student_guardians` documents no verified mapping from that code
 * to a name ("Father", "Mother", ...) exists, so nothing here invents one.
 */
const COLUMNS = ['StudentID', 'GuardianName', 'GuardianType', 'Address', 'Phone', 'Email', 'Remark'];

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

    $table = $opts['table'];
    $sql = "SELECT s.StudentID, g.GuardianName, g.GuardianType, g.Address, g.Phone, g.Email, g.Remark
            FROM `{$table}` g
            INNER JOIN `t_student` s ON s.PKID = g.Student_PKID
            ORDER BY g.PKID";
    $statement = $pdo->query($sql);

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

    fwrite(STDOUT, "Wrote {$rowCount} rows from `{$opts['database']}`.`{$table}` (joined to t_student) to {$outPath}\n");

    return 0;
}

exit(main($argv));
