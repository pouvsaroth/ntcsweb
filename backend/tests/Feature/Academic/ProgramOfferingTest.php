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

class ProgramOfferingTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_program_offering(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAM_OFFERINGS_CREATE]);
        $program = AcademicProgram::factory()->create(['code' => 'COM', 'name' => 'Computer']);
        $mode = StudyMode::factory()->create(['code' => 'PART_TIME', 'name' => 'Part Time']);
        $year = AcademicYear::factory()->create(['name' => '2026']);

        $response = $this->postJson('/api/v1/program-offerings', [
            'academic_program_id' => $program->id, 'study_mode_id' => $mode->id, 'academic_year_id' => $year->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.academic_year.name', '2026');
    }

    public function test_the_same_program_mode_and_year_combination_cannot_be_created_twice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAM_OFFERINGS_CREATE]);
        $program = AcademicProgram::factory()->create();
        $mode = StudyMode::factory()->create();
        $year = AcademicYear::factory()->create(['name' => '2026']);
        ProgramOffering::factory()->forProgram($program)->forStudyMode($mode)->forAcademicYear($year)->create();

        $response = $this->postJson('/api/v1/program-offerings', [
            'academic_program_id' => $program->id, 'study_mode_id' => $mode->id, 'academic_year_id' => $year->id,
        ]);

        $response->assertUnprocessable();
        $this->assertSame(1, ProgramOffering::count());
    }

    public function test_the_same_program_and_mode_may_repeat_in_a_different_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PROGRAM_OFFERINGS_CREATE]);
        $program = AcademicProgram::factory()->create();
        $mode = StudyMode::factory()->create();
        $year2026 = AcademicYear::factory()->create(['name' => '2026']);
        $year2027 = AcademicYear::factory()->create(['name' => '2027']);
        ProgramOffering::factory()->forProgram($program)->forStudyMode($mode)->forAcademicYear($year2026)->create();

        $response = $this->postJson('/api/v1/program-offerings', [
            'academic_program_id' => $program->id, 'study_mode_id' => $mode->id, 'academic_year_id' => $year2027->id,
        ]);

        $response->assertCreated();
        $this->assertSame(2, ProgramOffering::count());
    }
}
