<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Student;
use App\Models\StudentImport;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Streams an uploaded CSV of legacy student records into `students`, in
 * chunked bulk inserts, entirely off the request/response cycle — this is
 * the "big data" concern Redis actually solves here: without a queue, an
 * import of any real size either times out the HTTP request or blocks the
 * admin's browser for however long thousands of rows take to write.
 *
 * Column headers are matched case-insensitively against the exact names in
 * the legacy `t_student` table, so a plain `SELECT * FROM t_student` CSV
 * export needs no reformatting before upload. Unknown/extra columns (PKID,
 * Photo, created_at, updated_at) are ignored — Photo specifically is never
 * migrated by this job: the CSV has no image bytes, only whatever path the
 * old system used internally, which means nothing on this platform's
 * storage disk. Photos are re-uploaded per student after import, through the
 * same endpoint a manually created student uses.
 */
class ProcessStudentImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Written to `students`, and to the "already seen" duplicate check, this many rows at a time. */
    private const CHUNK_SIZE = 500;

    /** Past this many row-level errors, later ones still count toward `skipped_count` but aren't stored individually — an import gone this wrong needs a fixed file, not a longer error list. */
    private const MAX_RECORDED_ERRORS = 100;

    /** @var array<string, string> CSV header (lowercased) => Student column */
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

    public int $tries = 1; // A partially-imported file must not be silently re-run from row 1.

    public function __construct(private readonly StudentImport $import) {}

    public function handle(TenantContext $context): void
    {
        $context->runFor($this->import->tenant, function () {
            $this->process();
        });
    }

    private function process(): void
    {
        $this->import->update(['status' => StudentImport::STATUS_PROCESSING, 'started_at' => now()]);

        $handle = Storage::disk('local')->readStream($this->import->file_path);

        if ($handle === null) {
            $this->import->update([
                'status' => StudentImport::STATUS_FAILED,
                'errors' => [['row' => 0, 'message' => 'The uploaded file could not be read.']],
                'completed_at' => now(),
            ]);

            return;
        }

        try {
            $this->importRows($handle);
        } catch (Throwable $e) {
            report($e);

            $this->import->update([
                'status' => StudentImport::STATUS_FAILED,
                'errors' => [['row' => 0, 'message' => 'The import stopped unexpectedly: '.$e->getMessage()]],
                'completed_at' => now(),
            ]);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @param  resource  $handle
     */
    private function importRows($handle): void
    {
        $columns = $this->readHeader($handle);

        if ($columns === null) {
            $this->import->update([
                'status' => StudentImport::STATUS_FAILED,
                'errors' => [['row' => 0, 'message' => 'The file is empty or has no header row.']],
                'completed_at' => now(),
            ]);

            return;
        }

        $totalRows = 0;
        $importedCount = 0;
        $skippedCount = 0;
        $errors = [];
        $codesSeenInFile = [];

        /** @var list<array<string, mixed>> $buffer */
        $buffer = [];

        $flush = function () use (&$buffer, &$importedCount, &$skippedCount, &$errors) {
            if ($buffer === []) {
                return;
            }

            $existingCodes = Student::query()
                ->whereIn('student_code', array_column($buffer, 'student_code'))
                ->pluck('student_code')
                ->all();

            $existingCodes = array_flip($existingCodes);
            $now = now();
            $toInsert = [];

            foreach ($buffer as $row) {
                if (isset($existingCodes[$row['student_code']])) {
                    $skippedCount++;
                    $this->recordError($errors, $row['_row_number'], "Student code '{$row['student_code']}' already exists.");
                    continue;
                }

                unset($row['_row_number']);
                $toInsert[] = [
                    ...$row,
                    'tenant_id' => app(TenantContext::class)->idOrFail(),
                    'status' => Student::STATUS_ACTIVE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $importedCount++;
            }

            if ($toInsert !== []) {
                Student::query()->insert($toInsert);
            }

            $buffer = [];
        };

        while (($line = fgetcsv($handle)) !== false) {
            $totalRows++;

            if (count($line) === 1 && $line[0] === null) {
                continue; // A trailing blank line at end-of-file.
            }

            $rowNumber = $totalRows + 1; // +1 for the header row, so this matches the line a spreadsheet viewer shows.
            $data = $this->mapRow($columns, $line);

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
            $data['_row_number'] = $rowNumber;

            $buffer[] = $data;

            if (count($buffer) >= self::CHUNK_SIZE) {
                $flush();
            }
        }

        $flush();

        $this->import->update([
            'status' => StudentImport::STATUS_COMPLETED,
            'total_rows' => $totalRows,
            'imported_count' => $importedCount,
            'skipped_count' => $skippedCount,
            'errors' => $errors === [] ? null : $errors,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  resource  $handle
     * @return array<int, string>|null column index => Student field name
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
            // A malformed birthdate isn't a reason to lose the rest of a
            // student's record — it's just left blank, fixable later.
            return null;
        }
    }

    /**
     * @param  list<array{row: int, message: string}>  $errors
     */
    private function recordError(array &$errors, int $row, string $message): void
    {
        if (count($errors) < self::MAX_RECORDED_ERRORS) {
            $errors[] = ['row' => $row, 'message' => $message];
        }
    }
}
