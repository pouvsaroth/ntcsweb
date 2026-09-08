<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ApprovalRequest;
use App\Models\FormTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApprovalRequest>
 */
class ApprovalRequestFactory extends Factory
{
    protected $model = ApprovalRequest::class;

    public function definition(): array
    {
        return [
            'form_template_id' => FormTemplate::factory(),
            'requested_by' => User::factory(),
            'subject' => fake()->sentence(4),
            'details' => fake()->optional()->paragraph(),
            'status' => ApprovalRequest::STATUS_PENDING,
        ];
    }
}
