<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\District;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    protected $model = District::class;

    public function definition(): array
    {
        return [
            'province_id' => Province::factory(),
            'code' => fake()->unique()->numerify('000###'),
            'name_km' => fake()->city(),
            'name_latin' => fake()->city(),
            'unit_km' => 'ស្រុក',
            'unit_latin' => 'Srok',
            'unit_en' => 'District',
        ];
    }
}
