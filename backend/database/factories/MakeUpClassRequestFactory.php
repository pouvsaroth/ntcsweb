<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\MakeUpClassRequest;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MakeUpClassRequest>
 */
class MakeUpClassRequestFactory extends Factory
{
    protected $model = MakeUpClassRequest::class;

    public function definition(): array
    {
        $date = fake()->dateTimeBetween('+1 day', '+1 month')->format('Y-m-d');

        return [
            'student_id' => Student::factory(),
            'enrollment_id' => fn (array $attributes) => Enrollment::factory()->create(['student_id' => $attributes['student_id']])->id,
            'from_date' => $date,
            'to_date' => $date,
            'from_time' => '08:00',
            'to_time' => '10:00',
            'status' => MakeUpClassRequest::STATUS_PENDING,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }

    public function approvedToStudy(): static
    {
        return $this->state(['status' => MakeUpClassRequest::STATUS_APPROVED_TO_STUDY]);
    }

    public function approved(): static
    {
        return $this->state(['status' => MakeUpClassRequest::STATUS_APPROVED]);
    }

    public function rejected(): static
    {
        return $this->state(['status' => MakeUpClassRequest::STATUS_REJECTED]);
    }
}
