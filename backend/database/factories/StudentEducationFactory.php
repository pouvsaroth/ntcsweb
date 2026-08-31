<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentEducation;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentEducation>
 */
class StudentEducationFactory extends Factory
{
    protected $model = StudentEducation::class;

    public function definition(): array
    {
        return [
            'school_name' => fake()->company().' School',
            'address' => fake()->address(),
            'start_date' => fake()->dateTimeBetween('-6 years', '-2 years')->format('Y-m-d'),
            'end_date' => fake()->dateTimeBetween('-2 years', '-1 years')->format('Y-m-d'),
            'skill' => fake()->word(),
            'detail' => fake()->sentence(),
        ];
    }

    /**
     * See TeacherFactory::forTenant() — tenant_id is excluded from
     * $fillable, so this needs forceFill, not a plain state().
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (StudentEducation $education) use ($tenant) {
            $education->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forStudent(Student $student): static
    {
        return $this->state([
            'student_id' => $student->id,
        ])->forTenant($student->tenant_id);
    }
}
