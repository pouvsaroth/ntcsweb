<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Student;
use App\Services\Academic\StudentIdGenerator;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudentIdGeneratorTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_the_first_student_gets_number_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->createStudent();

        $response->assertJsonPath('data.student_code', 'NTS-000001');
    }

    public function test_the_second_student_gets_number_two(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $this->createStudent();
        $response = $this->createStudent();

        $response->assertJsonPath('data.student_code', 'NTS-000002');
    }

    #[DataProvider('paddingExamples')]
    public function test_the_sequence_is_zero_padded_to_six_digits(int $createCount, string $expectedLastCode): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        // Seed the counter directly rather than looping 999 real HTTP
        // requests — this test is about the sprintf('%06d', ...) formatting,
        // not about re-proving sequencing itself (covered above).
        DB::table('student_id_sequences')->insert([
            'tenant_id' => $this->tenant->id,
            'prefix' => 'NTS',
            'next_number' => $createCount,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->createStudent();

        $response->assertJsonPath('data.student_code', $expectedLastCode);
    }

    public static function paddingExamples(): array
    {
        return [
            'single digit' => [1, 'NTS-000001'],
            'two digits' => [25, 'NTS-000025'],
            'three digits' => [999, 'NTS-000999'],
            'four digits, no truncation' => [1000, 'NTS-001000'],
        ];
    }

    public function test_changing_the_prefix_starts_a_new_sequence(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE, Permissions::TENANT_SETTINGS_UPDATE]);

        $ntsResponse = $this->createStudent();
        $ntsResponse->assertJsonPath('data.student_code', 'NTS-000001');

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'ABC'])->assertOk();

        $abcResponse = $this->createStudent();
        $abcResponse->assertJsonPath('data.student_code', 'ABC-000001');

        // The earlier NTS student is untouched by the prefix change.
        $this->assertDatabaseHas('students', ['student_code' => 'NTS-000001']);
    }

    public function test_switching_back_to_a_previous_prefix_resumes_its_own_count(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE, Permissions::TENANT_SETTINGS_UPDATE]);

        $this->createStudent()->assertJsonPath('data.student_code', 'NTS-000001');

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'ABC'])->assertOk();
        $this->createStudent()->assertJsonPath('data.student_code', 'ABC-000001');

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'NTS'])->assertOk();
        $response = $this->createStudent();

        // NOT NTS-000001 — the NTS counter kept its own place the whole time.
        $response->assertJsonPath('data.student_code', 'NTS-000002');
    }

    public function test_the_database_rejects_a_duplicate_student_code_within_a_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        Student::factory()->create(['student_code' => 'NTS-000001']);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Student::factory()->create(['student_code' => 'NTS-000001']);
    }

    /**
     * PHPUnit can't fork real concurrent HTTP requests, so this proves the
     * generator itself never repeats a number across many sequential calls
     * within one process; the true concurrency guarantee is the
     * `SELECT ... FOR UPDATE` row lock in StudentIdGenerator::next() plus
     * the DB-level unique constraint proven above as the last line of
     * defense if that lock were ever bypassed.
     */
    public function test_many_sequential_generations_never_repeat_a_code(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);
        $tenant = $this->tenant;
        $generator = app(StudentIdGenerator::class);

        $codes = collect(range(1, 25))->map(fn () => $generator->next($tenant->fresh()));

        $this->assertCount(25, $codes->unique());
    }

    public function test_a_client_supplied_student_id_is_ignored_in_favor_of_the_generated_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->postJson('/api/v1/students', [
            'studentId' => 'NTS-999999',
            'student_code' => 'NTS-999999',
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'phone' => '012345678',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.student_code', 'NTS-000001');
    }

    public function test_a_deleted_students_code_is_never_reissued(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE, Permissions::STUDENTS_DELETE]);

        $first = $this->createStudent();
        $first->assertJsonPath('data.student_code', 'NTS-000001');
        $firstId = $first->json('data.id');

        $this->deleteJson("/api/v1/students/{$firstId}")->assertNoContent();

        $second = $this->createStudent();
        $second->assertJsonPath('data.student_code', 'NTS-000002');
    }

    private function createStudent(): \Illuminate\Testing\TestResponse
    {
        static $counter = 0;
        $counter++;

        return $this->postJson('/api/v1/students', [
            'first_name' => 'Student',
            'last_name' => (string) $counter,
            'phone' => '01000'.str_pad((string) $counter, 4, '0', STR_PAD_LEFT),
        ]);
    }
}
