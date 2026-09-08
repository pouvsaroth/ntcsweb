<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AcademicProgram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcademicProgram>
 */
class AcademicProgramFactory extends Factory
{
    protected $model = AcademicProgram::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('PRG???')),
            'name' => fake()->words(2, true),
        ];
    }
}
