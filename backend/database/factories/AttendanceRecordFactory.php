<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Support\Academic\AttendanceStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    protected $model = AttendanceRecord::class;

    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'date' => now()->toDateString(),
            'status' => AttendanceStatus::PRESENT,
        ];
    }

    /** Denormalizes class_id/student_id off the enrollment — see the model's docblock. */
    public function forEnrollment(Enrollment $enrollment): static
    {
        return $this->state([
            'enrollment_id' => $enrollment->getKey(),
            'class_id' => $enrollment->class_id,
            'student_id' => $enrollment->student_id,
        ]);
    }

    public function onDate(string $date): static
    {
        return $this->state(['date' => $date]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
