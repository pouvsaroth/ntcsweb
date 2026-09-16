<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\WebsiteVisit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebsiteVisit>
 */
class WebsiteVisitFactory extends Factory
{
    protected $model = WebsiteVisit::class;

    public function definition(): array
    {
        return [
            'visit_date' => fake()->date(),
            'visits' => fake()->numberBetween(1, 50),
        ];
    }
}
