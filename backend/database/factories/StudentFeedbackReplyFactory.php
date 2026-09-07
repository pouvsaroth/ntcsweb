<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StudentFeedback;
use App\Models\StudentFeedbackReply;
use App\Models\Tenant;
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

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (StudentFeedbackReply $reply) use ($tenant) {
            $reply->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
