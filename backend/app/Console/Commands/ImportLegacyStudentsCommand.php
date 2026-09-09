<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Stancl\Tenancy\Database\DatabaseManager;
use Throwable;

/**
 * Step 2 of the legacy student migration: load the CSV that
 * scripts/legacy-import/export-legacy-students.php produced into `students`
 * for one tenant.
 *
 * `students` lives in that tenant's own per-tenant database now, not a
 * shared one filtered by tenant_id — this runs from the CLI, outside any
 * request/queue context, so there is no ambient TenantContext to have
 * already pointed the `tenant` connection at the right physical database;
 * createTenantConnection() below does that explicitly instead, the same
 * call ResolveTenant/ProcessStudentImport make. A plain DB::table() writer
 * on that connection, not the Student Eloquent model, only because this
 * predates the CSV-column-mapping/validation logic being worth sharing with
 * the model layer. Column mapping, required-field checks, duplicate
 * handling, and date parsing all mirror App\Jobs\ProcessStudentImport so a
 * row that this command accepts or rejects would be accepted or rejected
 * the same way through the admin upload screen.
 *
 *   php artisan students:import-legacy storage/app/legacy-imports/t_student_export.csv \
 *     --tenant=1 --dry-run
 *
 * Drop --dry-run once the dry-run summary looks right.
 */
class ImportLegacyStudentsCommand extends Command
{
    protected $signature = 'students:import-legacy
        {file : Path to the CSV produced by export-legacy-students.php}
        {--tenant= : Numeric ID of the tenant these students belong to}
        {--dry-run : Parse and validate the file without writing anything}';

    protected $description = 'Import students from a legacy t_student CSV export into the students table for one tenant';

    private const CHUNK_SIZE = 500;

    private const MAX_LISTED_ERRORS = 50;

    /** @var array<string, string> CSV header (lowercased) => students column — same mapping as ProcessStudentImport::COLUMN_MAP */
    private const COLUMN_MAP = [
        'studentid' => 'student_code',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        'englishname' => 'english_name',
        'gender' => 'gender',
        'birthdate' => 'date_of_birth',
        'houseno' => 'house_no',
        'streetno' => 'street_no',
        'villagecode' => 'village_code',
        'otheraddress' => 'other_address',
        'studentphone' => 'phone',
        'studentemail' => 'email',
        'studentfacebook' => 'facebook',
        'studenttelegram' => 'telegram',
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

        $tenant = Tenant::query()->find($tenantId);
        if ($tenant === null) {
            $this->components->error("No tenant with id {$tenantId}.");

            return self::FAILURE;
        }

        app(DatabaseManager::class)->createTenantConnection($tenant);

        $dryRun = (bool) $this->option('dry-run');
        $this->components->info(sprintf(
            '%s students into tenant #%d (%s) from %s',
            $dryRun ? 'Validating' : 'Importing',
            $tenantId,
            $tenant->name,
            $path,
        ));

        $handle = fopen($path, 'r');
        if ($handle === false) {
            $this->components->error("Could not open {$path}.");

            return self::FAILURE;
        }

        try {
            return $this->importRows($handle, $dryRun);
        } finally {
            fclose($handle);
            app(DatabaseManager::class)->purgeTenantConnection();
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importRows($handle, bool $dryRun): int
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
        $codesSeenInFile = [];

        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        $flush = function () use (&$buffer, &$importedCount, &$skippedCount, &$errors, $dryRun) {
            if ($buffer === []) {
                return;
            }

            $codes = array_column($buffer, 'student_code');
            $existingCodes = DB::connection('tenant')->table('students')
                ->whereIn('student_code', $codes)
                ->pluck('student_code')
                ->flip();

            $now = now();
            $toInsert = [];

            foreach ($buffer as $row) {
                if ($existingCodes->has($row['student_code'])) {
                    $skippedCount++;
                    $this->recordError($errors, $row['_row_number'], "Student code '{$row['student_code']}' already exists for this tenant.");

                    continue;
                }

                unset($row['_row_number']);
                $toInsert[] = [
                    ...$row,
                    'status' => 'active',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $importedCount++;
            }

            if ($toInsert !== [] && ! $dryRun) {
                DB::connection('tenant')->table('students')->insert($toInsert);
            }

            $buffer = [];
        };

        while (($line = fgetcsv($handle)) !== false) {
            $totalRows++;

            if (count($line) === 1 && $line[0] === null) {
                continue; // trailing blank line
            }

            $rowNumber = $totalRows + 1; // +1 for the header row

            try {
                $data = $this->mapRow($columns, $line);
            } catch (Throwable $e) {
                $skippedCount++;
                $this->recordError($errors, $rowNumber, $e->getMessage());

                continue;
            }

            $code = trim((string) ($data['student_code'] ?? ''));
            $firstName = trim((string) ($data['first_name'] ?? ''));
            $lastName = trim((string) ($data['last_name'] ?? ''));

            if ($code === '' || $firstName === '' || $lastName === '') {
                $skippedCount++;
                $this->recordError($errors, $rowNumber, 'Missing required StudentID, FirstName, or LastName.');

                continue;
            }

            if (isset($codesSeenInFile[$code])) {
                $skippedCount++;
                $this->recordError($errors, $rowNumber, "Duplicate StudentID '{$code}' earlier in this file.");

                continue;
            }
            $codesSeenInFile[$code] = true;

            $data['student_code'] = $code;
            $data['first_name'] = $firstName;
            $data['last_name'] = $lastName;
            $data['date_of_birth'] = $this->parseDate($data['date_of_birth'] ?? null);
            $data['gender'] = $this->normalizeGender($data['gender'] ?? null);
            $data['village_code'] = $this->normalizeVillageCode($data['village_code'] ?? null);
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
     * @return array<int, string>|null column index => students field name
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

    private function parseDate(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The legacy system stores Gender as a bare "M"/"F" code, but this
     * platform's gender field holds a GENDER lookup code — 'male', 'female',
     * 'other' (see BaseDataSeeder) — so the raw letter is translated on the
     * way in rather than stored verbatim. An unrecognized value is passed
     * through trimmed rather than dropped, so it's still visible for
     * cleanup instead of silently disappearing.
     */
    private function normalizeGender(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match (strtoupper(trim($value))) {
            'M', 'MALE' => 'male',
            'F', 'FEMALE' => 'female',
            default => trim($value),
        };
    }

    /**
     * The legacy system drops VillageCode's leading zero wherever the code
     * itself starts with one (observed on real exported data: "8090111"
     * instead of the villages.code standard "08090111") — left-padding to 8
     * digits recovers the join to `villages` without guessing at anything
     * that isn't already implied by the fixed-width NIS code format. A code
     * already 8+ digits (correct, or wrong in some other way) is left alone
     * rather than truncated.
     */
    private function normalizeVillageCode(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return strlen($value) < 8 ? str_pad($value, 8, '0', STR_PAD_LEFT) : $value;
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
