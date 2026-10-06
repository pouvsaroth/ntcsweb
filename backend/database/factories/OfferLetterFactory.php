<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Applicant;
use App\Models\OfferLetter;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferLetter>
 */
class OfferLetterFactory extends Factory
{
    protected $model = OfferLetter::class;

    public function definition(): array
    {
        return [
            'applicant_id' => Applicant::factory(),
            'position_title' => fake()->jobTitle(),
            'employment_type' => 'full_time',
            'salary' => 500,
            'start_date' => now()->addMonth()->toDateString(),
            'status' => OfferLetter::STATUS_DRAFT,
        ];
    }
}
