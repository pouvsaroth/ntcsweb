<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ElectionOffice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ElectionOffice> */
class ElectionOfficeFactory extends Factory
{
    protected $model = ElectionOffice::class;

    public function definition(): array
    {
        return [
            'election_year' => 2027,
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->company(),
            'voters' => fake()->numberBetween(200, 900),
        ];
    }
}
