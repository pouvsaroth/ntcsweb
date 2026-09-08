<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LeaveRequest;
use App\Models\Student;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        $from = fake()->dateTimeBetween('now', '+2 weeks');

        return [
            'student_id' => Student::factory(),
            'from_date' => $from->format('Y-m-d'),
            'to_date' => $from->format('Y-m-d'),
            'from_time' => null,
            'to_time' => null,
            'reason' => fake()->sentence(10),
            'status' => LeaveRequest::STATUS_PENDING,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }
}
