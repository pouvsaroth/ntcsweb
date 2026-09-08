<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Student;
use App\Models\StudentFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentFeedback>
 */
class StudentFeedbackFactory extends Factory
{
    protected $model = StudentFeedback::class;

    public function definition(): array
    {
        return [
            'student_id' => Student::factory(),
            'type' => StudentFeedback::TYPE_COMMENT,
            'topic' => StudentFeedback::TOPIC_SCHOOL,
            'teacher_id' => null,
            'subject' => fake()->sentence(6),
            'message' => fake()->sentence(10),
            'status' => StudentFeedback::STATUS_OPEN,
        ];
    }

    public function forStudent(Student $student): static
    {
        return $this->state(['student_id' => $student->getKey()]);
    }

    public function aboutTeacher(int $teacherId): static
    {
        return $this->state([
            'topic' => StudentFeedback::TOPIC_TEACHER,
            'teacher_id' => $teacherId,
        ]);
    }
}
