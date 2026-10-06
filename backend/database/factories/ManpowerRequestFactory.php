<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ManpowerRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ManpowerRequest>
 */
class ManpowerRequestFactory extends Factory
{
    protected $model = ManpowerRequest::class;

    public function definition(): array
    {
        return [
            'job_title' => fake()->jobTitle(),
            'headcount' => fake()->numberBetween(1, 3),
            'employment_type' => 'full_time',
            'needed_by' => fake()->dateTimeBetween('+2 weeks', '+3 months')->format('Y-m-d'),
            'reason' => fake()->sentence(8),
            'requested_by' => User::factory(),
            'status' => ManpowerRequest::STATUS_PENDING,
        ];
    }
}
