<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\Student;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamApplication>
 */
class ExamApplicationFactory extends Factory
{
    protected $model = ExamApplication::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'enrollment_id' => Enrollment::factory(),
            'exam_date' => fake()->dateTimeBetween('+1 week', '+1 month')->format('Y-m-d'),
            'exam_time' => '09:00:00',
            'table_no' => (string) fake()->numberBetween(1, 30),
            'fee_amount' => 25,
            'fee_currency' => Tenant::CURRENCY_USD,
            'student_marked_paid_at' => now(),
            'status' => ExamApplication::STATUS_PENDING,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ExamApplication $application) use ($tenant) {
            $application->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }

    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state(['enrollment_id' => $enrollment->getKey()]);
    }
}
