<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\Tenant;
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
            'name' => fake()->name(),
            'date_of_birth' => fake()->dateTimeBetween('-25 years', '-10 years')->format('Y-m-d'),
            'gender' => fake()->randomElement(['male', 'female']),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'guardian_name' => fake()->name(),
            'guardian_phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'enrollment_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'status' => Student::STATUS_ACTIVE,
            'user_id' => null,
        ];
    }

    /**
     * See TeacherFactory::forTenant() — tenant_id is excluded from
     * Student::$fillable, so this needs forceFill, not a plain state().
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Student $student) use ($tenant) {
            $student->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
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
