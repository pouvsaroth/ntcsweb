<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Commune;
use App\Models\Village;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Village>
 */
class VillageFactory extends Factory
{
    protected $model = Village::class;

    public function definition(): array
    {
        return [
            'commune_id' => Commune::factory(),
            'code' => fake()->unique()->numerify('########'),
            'name_km' => fake()->streetName(),
            'name_latin' => fake()->streetName(),
            'unit_km' => 'ភូមិ',
            'unit_latin' => 'Phum',
            'unit_en' => 'Village',
        ];
    }
}
