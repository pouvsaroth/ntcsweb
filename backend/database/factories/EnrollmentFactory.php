<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Enrollment>
 */
class EnrollmentFactory extends Factory
{
    protected $model = Enrollment::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'class_id' => SchoolClass::factory(),
            'enrolled_at' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'status' => Enrollment::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Enrollment $enrollment) use ($tenant) {
            $enrollment->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }

    public function forClass(SchoolClass $class): static
    {
        return $this->state(['class_id' => $class->getKey()]);
    }

    public function dropped(): static
    {
        return $this->state(['status' => Enrollment::STATUS_DROPPED]);
    }
}
