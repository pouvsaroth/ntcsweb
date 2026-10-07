<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffSalary;
use App\Models\Staff;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffSalary>
 */
class StaffSalaryFactory extends Factory
{
    protected $model = StaffSalary::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'basic_salary' => 500,
            'currency' => Tenant::CURRENCY_USD,
            'effective_from' => now()->startOfMonth()->toDateString(),
            'payment_method' => StaffSalary::PAYMENT_BANK,
        ];
    }
}
