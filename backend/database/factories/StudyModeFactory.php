<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StudyMode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudyMode>
 */
class StudyModeFactory extends Factory
{
    protected $model = StudyMode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('MODE???')),
            'name' => fake()->words(2, true),
        ];
    }
}
