<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        return [
            'student_code' => 'S-'.fake()->unique()->numerify('#####'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'english_name' => null,
            'date_of_birth' => fake()->dateTimeBetween('-25 years', '-10 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'house_no' => null,
            'street_no' => null,
            'village_code' => null,
            'other_address' => fake()->address(),
            'facebook' => null,
            'telegram' => null,
            'photo_path' => null,
            'enrollment_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'status' => Student::STATUS_ACTIVE,
            'user_id' => null,
        ];
    }

    public function withdrawn(): static
    {
        return $this->state(['status' => Student::STATUS_WITHDRAWN]);
    }

    public function graduated(): static
    {
        return $this->state(['status' => Student::STATUS_GRADUATED]);
    }
}
