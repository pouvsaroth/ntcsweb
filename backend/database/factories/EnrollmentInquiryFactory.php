<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EnrollmentInquiry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrollmentInquiry>
 */
class EnrollmentInquiryFactory extends Factory
{
    protected $model = EnrollmentInquiry::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->numerify('0## ### ###'),
            'email' => fake()->safeEmail(),
            'program_id' => null,
            'message' => fake()->sentence(),
        ];
    }
}
