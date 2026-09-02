<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Language;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Language>
 */
class LanguageFactory extends Factory
{
    protected $model = Language::class;

    public function definition(): array
    {
        $code = fake()->unique()->languageCode();

        return [
            'code' => $code,
            'name' => fake()->words(2, true),
            'native_name' => fake()->words(2, true),
            'is_active' => true,
            'is_default' => false,
            'sort_order' => 0,
        ];
    }

    public function default(): static
    {
        return $this->state(['is_default' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
