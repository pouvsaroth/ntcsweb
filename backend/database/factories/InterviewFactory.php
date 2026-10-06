<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\Interview;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interview>
 */
class InterviewFactory extends Factory
{
    protected $model = Interview::class;

    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            'scheduled_at' => now()->addDays(2)->setTime(9, 0),
            'mode' => 'in_person',
            'status' => Interview::STATUS_SCHEDULED,
        ];
    }
}
