<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentEducation;
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

    public function forStudent(Student $student): static
    {
        return $this->state([
            'student_id' => $student->id,
        ]);
    }
}
