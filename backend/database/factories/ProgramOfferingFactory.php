<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use App\Models\AcademicYear;
use App\Models\ProgramOffering;
use App\Models\StudyMode;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProgramOffering>
 */
class ProgramOfferingFactory extends Factory
{
    protected $model = ProgramOffering::class;

    public function definition(): array
    {
        return [
            'academic_program_id' => AcademicProgram::factory(),
            'study_mode_id' => StudyMode::factory(),
            'academic_year_id' => AcademicYear::factory(),
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ProgramOffering $offering) use ($tenant) {
            $offering->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forProgram(AcademicProgram $program): static
    {
        return $this->state(['academic_program_id' => $program->getKey()]);
    }

    public function forStudyMode(StudyMode $mode): static
    {
        return $this->state(['study_mode_id' => $mode->getKey()]);
    }

    public function forAcademicYear(AcademicYear $year): static
    {
        return $this->state(['academic_year_id' => $year->getKey()]);
    }
}
