<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Jobs\ProcessStudentImport;
use App\Models\Student;
use App\Models\StudentImport;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudentImportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_uploading_a_csv_creates_a_pending_import_and_dispatches_the_job(): void
    {
        Storage::fake('local');
        Queue::fake();
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->post('/api/v1/student-imports', [
            'file' => UploadedFile::fake()->createWithContent('students.csv', "StudentID,FirstName,LastName\nT-1,Sopheak,Chan\n"),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.status', StudentImport::STATUS_PENDING);
        $response->assertJsonPath('data.original_filename', 'students.csv');

        $import = StudentImport::first();
        Storage::disk('local')->assertExists($import->file_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/student-imports/", $import->file_path);

        Queue::assertPushed(ProcessStudentImport::class);
    }

    public function test_the_job_imports_rows_mapped_from_the_legacy_column_headers(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_CREATE]);

        $csv = implode("\n", [
            'StudentID,FirstName,LastName,EnglishName,Gender,BirthDate,HouseNo,StreetNo,VillageCode,OtherAddress,StudentPhone,StudentEmail,StudentFacebook,StudentTelegram',
            'T-1001,Sopheak,Chan,Sophea Chan,M,2005-03-14,12,271,120101,Phnom Penh,012345678,sopheak@example.com,sopheak.chan,@sopheakchan',
            'T-1002,Dara,Sok,,F,2006-07-01,,,,,,,,',
        ]);

        $import = $this->makeImport($csv);

        (new ProcessStudentImport($import))->handle(app(TenantContext::class));

        $import->refresh();
        $this->assertSame(StudentImport::STATUS_COMPLETED, $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(2, $import->imported_count);
        $this->assertSame(0, $import->skipped_count);

        $student = Student::query()->where('student_code', 'T-1001')->firstOrFail();
        $this->assertSame('Sopheak', $student->first_name);
        $this->assertSame('Sophea Chan', $student->english_name);
        $this->assertSame('120101', $student->village_code);
        $this->assertSame('2005-03-14', $student->date_of_birth->toDateString());
        $this->assertSame(Student::STATUS_ACTIVE, $student->status);

        $second = Student::query()->where('student_code', 'T-1002')->firstOrFail();
        $this->assertNull($second->english_name);
        $this->assertSame('2006-07-01', $second->date_of_birth->toDateString());
    }

    public function test_a_row_missing_a_required_field_is_skipped_and_recorded(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $csv = implode("\n", [
            'StudentID,FirstName,LastName',
            ',NoCode,HasNoStudentId',
            'T-2001,,MissingFirstName',
        ]);

        $import = $this->makeImport($csv);
        (new ProcessStudentImport($import))->handle(app(TenantContext::class));

        $import->refresh();
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(0, $import->imported_count);
        $this->assertSame(2, $import->skipped_count);
        $this->assertCount(2, $import->errors);
        $this->assertSame(2, $import->errors[0]['row']);
    }

    public function test_duplicate_student_codes_within_the_file_and_against_existing_students_are_skipped(): void
    {
        Storage::fake('local');
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        Student::factory()->create(['student_code' => 'T-3000']);

        $csv = implode("\n", [
            'StudentID,FirstName,LastName',
            'T-3000,Already,Exists',
            'T-3001,First,Row',
            'T-3001,Duplicate,InFile',
        ]);

        $import = $this->makeImport($csv);
        (new ProcessStudentImport($import))->handle(app(TenantContext::class));

        $import->refresh();
        $this->assertSame(3, $import->total_rows);
        $this->assertSame(1, $import->imported_count);
        $this->assertSame(2, $import->skipped_count);
        $this->assertSame(1, Student::query()->where('student_code', 'T-3001')->count());
    }

    private function makeImport(string $csvContent): StudentImport
    {
        $path = $this->tenant->storagePath('student-imports').'/'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csvContent);

        return StudentImport::query()->create([
            'user_id' => $this->admin->id,
            'original_filename' => 'students.csv',
            'file_path' => $path,
            'status' => StudentImport::STATUS_PENDING,
        ]);
    }
}
