<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Student;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            'name' => 'Chan Sopheak',
            'guardian_name' => 'Chan Vuthy',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('students', ['student_code' => 'S-00001', 'tenant_id' => $this->tenant->id]);
    }

    public function test_student_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);
        Student::factory()->create(['student_code' => 'S-00001']);

        $response = $this->postJson('/api/v1/students', ['student_code' => 'S-00001', 'name' => 'Someone Else']);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('student_code');
    }

    public function test_staff_can_create_but_not_delete_a_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_CREATE]);
        $student = Student::factory()->create();

        $this->postJson('/api/v1/students', ['student_code' => 'S-00002', 'name' => 'New Student'])
            ->assertCreated();

        $this->deleteJson("/api/v1/students/{$student->id}")->assertForbidden();
    }

    public function test_a_student_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $otherStudent = $this->createForOtherTenant(fn () => Student::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/students/{$otherStudent->id}")->assertNotFound();
    }

    public function test_search_matches_name_and_guardian_name(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        Student::factory()->create(['name' => 'Chan Sopheak', 'guardian_name' => 'Chan Vuthy']);
        Student::factory()->create(['name' => 'Sok Dara', 'guardian_name' => 'Sok Bopha']);

        $response = $this->getJson('/api/v1/students?search=Sopheak');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.name', 'Chan Sopheak');
    }
}
