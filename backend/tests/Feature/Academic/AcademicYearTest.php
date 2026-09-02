<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\ProgramOffering;
use App\Models\StudyMode;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AcademicYearTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_an_academic_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_YEARS_CREATE]);

        $response = $this->postJson('/api/v1/academic-years', [
            'name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', '2026');
    }

    public function test_a_duplicate_name_within_the_same_tenant_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_YEARS_CREATE]);
        AcademicYear::factory()->create(['name' => '2026']);

        $this->postJson('/api/v1/academic-years', ['name' => '2026'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_marking_a_year_current_unmarks_the_previous_current_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_YEARS_CREATE, Permissions::ACADEMIC_YEARS_UPDATE]);
        $year2025 = AcademicYear::factory()->create(['name' => '2025', 'is_current' => true]);

        $year2026Id = $this->postJson('/api/v1/academic-years', ['name' => '2026', 'is_current' => true])
            ->assertCreated()->json('data.id');

        $this->assertFalse((bool) $year2025->fresh()->is_current);
        $this->assertTrue((bool) AcademicYear::findOrFail($year2026Id)->is_current);
    }

    public function test_a_year_used_by_a_program_offering_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ACADEMIC_YEARS_DELETE]);
        $year = AcademicYear::factory()->create();
        ProgramOffering::factory()
            ->forProgram(AcademicProgram::factory()->create())
            ->forStudyMode(StudyMode::factory()->create())
            ->forAcademicYear($year)
            ->create();

        $this->deleteJson("/api/v1/academic-years/{$year->id}")->assertStatus(422);
        $this->assertNotNull($year->fresh());
    }
}
