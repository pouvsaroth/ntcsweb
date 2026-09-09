<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentGuardian;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentGuardian>
 */
class StudentGuardianFactory extends Factory
{
    protected $model = StudentGuardian::class;

    public function definition(): array
    {
        return [
            'guardian_name' => fake()->name(),
            'guardian_type' => fake()->randomElement(['Father', 'Mother', 'Guardian']),
            'address' => fake()->address(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->safeEmail(),
            'remark' => null,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state([
            'student_id' => $student->id,
        ]);
    }
}
