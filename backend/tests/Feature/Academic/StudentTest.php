<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Student;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudentTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_lists_students_for_the_current_tenant_only_using_cursor_pagination(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);

        Student::factory()->count(3)->create();
        $this->createForOtherTenant(fn () => Student::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->getJson('/api/v1/students');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        // Cursor, not length-aware — this table is designed for millions of
        // rows, so it must never expose (or compute) a total row count.
        $response->assertJsonPath('meta.pagination.type', 'cursor');
    }

    public function test_it_creates_a_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->postJson('/api/v1/students', [
            'student_code' => 'S-00001',
            'first_name' => 'Sopheak',
            'last_name' => 'Chan',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.full_name', 'Sopheak Chan');
        $this->assertDatabaseHas('students', ['student_code' => 'S-00001', 'tenant_id' => $this->tenant->id]);
    }

    /**
     * Mirrors the legacy `t_student` shape (see the restructuring
     * migration): a house/street/village address instead of one free-text
     * field, plus the social contacts and photo it also tracks.
     */
    public function test_it_creates_a_student_with_the_full_legacy_field_set_and_a_photo(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->post('/api/v1/students', [
            'student_code' => 'S-00099',
            'first_name' => 'Sopheak',
            'last_name' => 'Chan',
            'english_name' => 'Sophea Chan',
            'house_no' => '12',
            'street_no' => '271',
            'village_code' => '120101',
            'other_address' => 'Phnom Penh',
            'facebook' => 'sopheak.chan',
            'telegram' => '@sopheakchan',
            'photo' => UploadedFile::fake()->image('student.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.village_code', '120101');
        $response->assertJsonPath('data.english_name', 'Sophea Chan');

        $student = Student::first();
        Storage::disk('public')->assertExists($student->photo_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/students/", $student->photo_path);
    }

    public function test_student_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);
        Student::factory()->create(['student_code' => 'S-00001']);

        $response = $this->postJson('/api/v1/students', [
            'student_code' => 'S-00001', 'first_name' => 'Someone', 'last_name' => 'Else',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('student_code');
    }

    public function test_staff_can_create_but_not_delete_a_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_CREATE]);
        $student = Student::factory()->create();

        $this->postJson('/api/v1/students', ['student_code' => 'S-00002', 'first_name' => 'New', 'last_name' => 'Student'])
            ->assertCreated();

        $this->deleteJson("/api/v1/students/{$student->id}")->assertForbidden();
    }

    public function test_a_student_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $otherStudent = $this->createForOtherTenant(fn () => Student::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/students/{$otherStudent->id}")->assertNotFound();
    }

    public function test_search_matches_first_and_last_name(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        Student::factory()->create(['first_name' => 'Sopheak', 'last_name' => 'Chan']);
        Student::factory()->create(['first_name' => 'Dara', 'last_name' => 'Sok']);

        $response = $this->getJson('/api/v1/students?search=Sopheak');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.full_name', 'Sopheak Chan');
    }

    /**
     * A student can have more than one guardian (father, mother, other) and
     * more than one prior school — see the student_guardians/
     * student_educations migrations, mirroring the legacy system's own
     * separate tables rather than flat columns on the student.
     */
    public function test_it_registers_a_student_with_guardians_and_education_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->postJson('/api/v1/students', [
            'student_code' => 'S-00050',
            'first_name' => 'Sopheak',
            'last_name' => 'Chan',
            'guardians' => [
                ['guardian_name' => 'Chan Vuthy', 'guardian_type' => 'Father', 'phone' => '012345678'],
                ['guardian_name' => 'Sok Bopha', 'guardian_type' => 'Mother', 'phone' => '098765432', 'email' => 'bopha@example.com'],
            ],
            'educations' => [
                ['school_name' => 'Preah Sisowath High School', 'address' => 'Phnom Penh', 'start_date' => '2018-01-01', 'end_date' => '2022-06-30', 'skill' => 'General', 'detail' => 'Completed grade 12.'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.guardians');
        $response->assertJsonPath('data.guardians.0.guardian_name', 'Chan Vuthy');
        $response->assertJsonPath('data.guardians.1.email', 'bopha@example.com');
        $response->assertJsonCount(1, 'data.educations');
        $response->assertJsonPath('data.educations.0.school_name', 'Preah Sisowath High School');

        $student = Student::where('student_code', 'S-00050')->firstOrFail();
        $this->assertSame(2, $student->guardians()->count());
        $this->assertSame($this->tenant->id, $student->guardians()->first()->tenant_id);
    }

    public function test_a_guardian_missing_its_required_phone_fails_validation(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->postJson('/api/v1/students', [
            'student_code' => 'S-00051', 'first_name' => 'New', 'last_name' => 'Student',
            'guardians' => [['guardian_name' => 'No Phone', 'guardian_type' => 'Father']],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('guardians.0.phone');
    }

    public function test_updating_guardians_replaces_the_whole_list(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create();
        $student->guardians()->create([
            'guardian_name' => 'Old Guardian', 'guardian_type' => 'Father', 'phone' => '011111111',
        ]);

        $response = $this->putJson("/api/v1/students/{$student->id}", [
            'guardians' => [
                ['guardian_name' => 'New Guardian', 'guardian_type' => 'Mother', 'phone' => '022222222'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.guardians');
        $response->assertJsonPath('data.guardians.0.guardian_name', 'New Guardian');
        $this->assertSame(1, $student->guardians()->count());
    }

    public function test_updating_without_the_guardians_key_leaves_existing_guardians_untouched(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create();
        $student->guardians()->create([
            'guardian_name' => 'Kept Guardian', 'guardian_type' => 'Father', 'phone' => '011111111',
        ]);

        $this->putJson("/api/v1/students/{$student->id}", ['first_name' => 'Renamed'])->assertOk();

        $this->assertSame(1, $student->guardians()->count());
    }

    public function test_replacing_a_photo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create(['photo_path' => 'tenants/'.$this->tenant->id.'/students/old.jpg']);
        Storage::disk('public')->put($student->photo_path, 'fake-old-photo');

        $response = $this->post("/api/v1/students/{$student->id}", [
            '_method' => 'PUT',
            'photo' => UploadedFile::fake()->image('new.jpg'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        Storage::disk('public')->assertMissing('tenants/'.$this->tenant->id.'/students/old.jpg');
        Storage::disk('public')->assertExists($student->fresh()->photo_path);
    }
}
