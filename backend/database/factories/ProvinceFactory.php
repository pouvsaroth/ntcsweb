<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    protected $model = Province::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('##'),
            'name_km' => fake()->city(),
            'name_latin' => fake()->city(),
            'unit_km' => 'ខេត្ត',
            'unit_latin' => 'Khaet',
            'unit_en' => 'Province',
        ];
    }
}
