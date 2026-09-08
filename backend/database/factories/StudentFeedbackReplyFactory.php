<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StudentFeedback;
use App\Models\StudentFeedbackReply;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentFeedbackReply>
 */
class StudentFeedbackReplyFactory extends Factory
{
    protected $model = StudentFeedbackReply::class;

    public function definition(): array
    {
        return [
            'student_feedback_id' => StudentFeedback::factory(),
            'user_id' => User::factory(),
            'body' => fake()->sentence(),
        ];
    }
}
