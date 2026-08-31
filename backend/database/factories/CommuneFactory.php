<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Commune;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Commune>
 */
class CommuneFactory extends Factory
{
    protected $model = Commune::class;

    public function definition(): array
    {
        return [
            'district_id' => District::factory(),
            'code' => fake()->unique()->numerify('#####'),
            'name_km' => fake()->streetName(),
            'name_latin' => fake()->streetName(),
            'unit_km' => 'ឃុំ',
            'unit_latin' => 'Khum',
            'unit_en' => 'Commune',
        ];
    }
}
