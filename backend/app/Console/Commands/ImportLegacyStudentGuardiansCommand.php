<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Step 2 of the legacy student-guardian migration: load the CSV that
 * scripts/legacy-import/export-legacy-student-guardians.php produced into
 * `student_guardians` for one tenant.
 *
 * Same DB::table() approach as ImportLegacyStudentsCommand, for the same
 * reason — no ambient TenantContext to resolve outside a request/queue.
 * `student_id` is resolved from the CSV's StudentID against `students`
 * rather than trusted from any surrogate key, since the legacy
 * Student_PKID never made the trip (see the export script's doc comment).
 *
 * `guardian_type` is translated from the legacy system's raw int code to a
 * GUARDIAN_TYPE lookup code via a mapping confirmed against the old
 * system's real dropdown (see normalizeGuardianType()) — the migration
 * that created this table originally assumed no such mapping was
 * verifiable and stored the raw code instead; this supersedes that.
 *
 *   docker compose exec -e DB_DATABASE=ntcsdbtest php \
 *     php artisan students:import-legacy-guardians storage/app/legacy-imports/t_school_student_guardian_export.csv \
 *     --tenant=1 --dry-run
 */
class ImportLegacyStudentGuardiansCommand extends Command
{
    protected $signature = 'students:import-legacy-guardians
        {file : Path to the CSV produced by export-legacy-student-guardians.php}
        {--tenant= : Numeric ID of the tenant these guardians belong to}
        {--dry-run : Parse and validate the file without writing anything}
        {--force : Import even if this tenant already has student_guardians rows}';

    protected $description = 'Import student guardians from a legacy t_school_student_guardian CSV export for one tenant';

    private const CHUNK_SIZE = 500;

    private const MAX_LISTED_ERRORS = 50;

    /** @var array<string, string> CSV header (lowercased) => student_guardians column */
    private const COLUMN_MAP = [
        'studentid' => 'student_code',
        'guardianname' => 'guardian_name',
        'guardiantype' => 'guardian_type',
        'address' => 'address',
        'phone' => 'phone',
        'email' => 'email',
        'remark' => 'remark',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error("File not found or not readable: {$path}");

            return self::FAILURE;
        }

        $tenantId = $this->option('tenant');
        if ($tenantId === null || ! ctype_digit($tenantId)) {
            $this->components->error('--tenant=<id> is required and must be numeric.');

            return self::FAILURE;
        }
        $tenantId = (int) $tenantId;

        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if ($tenant === null) {
            $this->components->error("No tenant with id {$tenantId} in this database (".DB::connection()->getDatabaseName().').');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $existing = DB::table('student_guardians')->where('tenant_id', $tenantId)->count();
        if ($existing > 0 && ! $dryRun && ! $this->option('force')) {
            $this->components->error(
                "Tenant #{$tenantId} already has {$existing} student_guardians row(s) — this table has no natural key to "
                .'dedupe against, so re-running would duplicate them. Pass --force to import anyway.'
            );

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            '%s student guardians into tenant #%d (%s) on database "%s" from %s',
            $dryRun ? 'Validating' : 'Importing',
            $tenantId,
            $tenant->name,
            DB::connection()->getDatabaseName(),
            $path,
        ));

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->components->error("Could not open {$path}.");

            return self::FAILURE;
        }

        try {
            return $this->importRows($handle, $tenantId, $dryRun);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importRows($handle, int $tenantId, bool $dryRun): int
    {
        $columns = $this->readHeader($handle);
        if ($columns === null) {
            $this->components->error('The file is empty or has no header row.');

            return self::FAILURE;
        }

        $totalRows = 0;
        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];

        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        $flush = function () use (&$buffer, &$importedCount, &$skippedCount, &$errors, $tenantId, $dryRun) {
            if ($buffer === []) {
                return;
            }

            $codes = array_unique(array_column($buffer, 'student_code'));
            $studentIdsByCode = DB::table('students')
                ->where('tenant_id', $tenantId)
                ->whereIn('student_code', $codes)
                ->pluck('id', 'student_code');

            $now = now();
            $toInsert = [];

            foreach ($buffer as $row) {
                $studentId = $studentIdsByCode[$row['student_code']] ?? null;

                if ($studentId === null) {
                    $skippedCount++;
                    $this->recordError($errors, $row['_row_number'], "No student with code '{$row['student_code']}' for this tenant.");

                    continue;
                }

                unset($row['_row_number'], $row['student_code']);
                $toInsert[] = [
                    ...$row,
                    'tenant_id' => $tenantId,
                    'student_id' => $studentId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $importedCount++;
            }

            if ($toInsert !== [] && ! $dryRun) {
                DB::table('student_guardians')->insert($toInsert);
            }

            $buffer = [];
        };

        while (($line = fgetcsv($handle)) !== false) {
            $totalRows++;

            if (count($line) === 1 && $line[0] === null) {
                continue; // trailing blank line
            }

            $rowNumber = $totalRows + 1; // +1 for the header row
            $data = $this->mapRow($columns, $line);

            $code = trim((string) ($data['student_code'] ?? ''));
            $guardianName = trim((string) ($data['guardian_name'] ?? ''));
            $phone = trim((string) ($data['phone'] ?? ''));

            if ($code === '' || $guardianName === '' || $phone === '') {
                $skippedCount++;
                $this->recordError($errors, $rowNumber, 'Missing required StudentID, GuardianName, or Phone.');

                continue;
            }

            $data['student_code'] = $code;
            $data['guardian_name'] = $guardianName;
            $data['guardian_type'] = $this->normalizeGuardianType($data['guardian_type'] ?? null);
            $data['phone'] = $phone;
            $data['_row_number'] = $rowNumber;

            $buffer[] = $data;

            if (count($buffer) >= self::CHUNK_SIZE) {
                $flush();
            }
        }

        $flush();

        $this->newLine();
        $this->components->twoColumnDetail('Total data rows', (string) $totalRows);
        $this->components->twoColumnDetail($dryRun ? 'Would import' : 'Imported', (string) $importedCount);
        $this->components->twoColumnDetail('Skipped', (string) $skippedCount);

        if ($errors !== []) {
            $this->newLine();
            $this->components->warn(count($errors).' row(s) with problems'.(count($errors) >= self::MAX_LISTED_ERRORS ? ' (showing first '.self::MAX_LISTED_ERRORS.')' : ':'));
            foreach ($errors as $error) {
                $this->line("  Row {$error['row']}: {$error['message']}");
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->components->info('Dry run only — nothing was written. Re-run without --dry-run to import.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>|null column index => student_guardians field name
     */
    private function readHeader($handle): ?array
    {
        $header = fgetcsv($handle);
        if ($header === false || $header === null) {
            return null;
        }

        $columns = [];
        foreach ($header as $index => $label) {
            $key = strtolower(trim((string) $label));
            if (isset(self::COLUMN_MAP[$key])) {
                $columns[$index] = self::COLUMN_MAP[$key];
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<int, string|null>  $line
     * @return array<string, mixed>
     */
    private function mapRow(array $columns, array $line): array
    {
        $data = [];
        foreach ($columns as $index => $field) {
            $value = $line[$index] ?? null;
            $value = $value === null ? null : trim($value);
            $data[$field] = $value === '' ? null : $value;
        }

        return $data;
    }

    /** @var array<string, string> legacy GuardianType int code => GUARDIAN_TYPE lookup code */
    private const GUARDIAN_TYPE_MAP = [
        '1' => 'FATHER',
        '2' => 'MOTHER',
        '3' => 'GRANDPARENT',
        '4' => 'GRANDPARENT',
        '5' => 'BROTHER',
        '6' => 'SISTER',
        '9' => 'UNCLE',
        '10' => 'AUNT',
    ];

    /** Any code not in the confirmed mapping — including a blank one — becomes OTHER rather than being dropped. */
    private function normalizeGuardianType(?string $value): string
    {
        return self::GUARDIAN_TYPE_MAP[trim((string) $value)] ?? 'OTHER';
    }

    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    private function recordError(array &$errors, int $row, string $message): void
    {
        if (count($errors) < self::MAX_LISTED_ERRORS) {
            $errors[] = ['row' => $row, 'message' => $message];
        }
    }
}
