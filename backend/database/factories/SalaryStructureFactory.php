<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SalaryStructure;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalaryStructure>
 */
class SalaryStructureFactory extends Factory
{
    protected $model = SalaryStructure::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('SS???')),
            'name' => 'Full-time teacher',
            'currency' => Tenant::CURRENCY_USD,
        ];
    }
}
