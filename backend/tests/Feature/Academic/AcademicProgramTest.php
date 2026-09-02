<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\CoursePackage;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AcademicProgramTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_program(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_PROGRAMS_CREATE]);

        $response = $this->postJson('/api/v1/academic-programs', [
            'code' => 'COM', 'name' => 'Computer',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.code', 'COM');
        $response->assertJsonPath('data.name', 'Computer');
    }

    public function test_a_duplicate_code_within_the_same_tenant_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_PROGRAMS_CREATE]);
        AcademicProgram::factory()->create(['code' => 'COM']);

        $this->postJson('/api/v1/academic-programs', ['code' => 'COM', 'name' => 'Computer 2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_a_program_used_by_a_course_package_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_PROGRAMS_DELETE]);
        $program = AcademicProgram::factory()->create();
        CoursePackage::factory()->forProgram($program)->create();

        $this->deleteJson("/api/v1/academic-programs/{$program->id}")->assertStatus(422);
        $this->assertNotNull($program->fresh());
    }

    public function test_an_unused_program_can_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_PROGRAMS_DELETE]);
        $program = AcademicProgram::factory()->create();

        $this->deleteJson("/api/v1/academic-programs/{$program->id}")->assertNoContent();
        $this->assertNull(AcademicProgram::find($program->id));
    }
}
