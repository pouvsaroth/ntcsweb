<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\JobPosition;
use App\Models\JobPosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    protected $model = JobPosting::class;

    public function definition(): array
    {
        return [
            'job_position_id' => JobPosition::factory(),
            'channel' => JobPosting::CHANNEL_WEBSITE,
            'posted_on' => now()->subDay()->toDateString(),
            'is_active' => true,
        ];
    }
}
