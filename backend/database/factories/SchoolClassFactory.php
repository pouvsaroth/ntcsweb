<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    protected $model = SchoolClass::class;

    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Excel Basics', 'Web Development', 'Graphic Design', 'English Speaking']).' — Batch '.fake()->unique()->numberBetween(1, 999),
            'code' => null,
            'teacher_id' => null,
            'classroom_id' => null,
            'capacity' => fake()->numberBetween(10, 30),
            'start_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'end_date' => null,
            'status' => SchoolClass::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (SchoolClass $class) use ($tenant) {
            $class->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function withTeacher(Staff $teacher): static
    {
        return $this->state(['teacher_id' => $teacher->getKey()]);
    }

    public function inRoom(Classroom $classroom): static
    {
        return $this->state(['classroom_id' => $classroom->getKey()]);
    }

    public function forProgram(AcademicProgram $program): static
    {
        return $this->state(['academic_program_id' => $program->getKey()]);
    }
}
