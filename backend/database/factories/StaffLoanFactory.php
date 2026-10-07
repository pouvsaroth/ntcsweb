<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaffLoan>
 */
class StaffLoanFactory extends Factory
{
    protected $model = StaffLoan::class;

    public function definition(): array
    {
        return [
            'staff_id' => Staff::factory(),
            'type' => StaffLoan::TYPE_LOAN,
            'amount' => 300,
            'currency' => Tenant::CURRENCY_USD,
            'issued_on' => now()->toDateString(),
            'installment_amount' => 100,
            'first_deduction_on' => now()->addMonth()->startOfMonth()->toDateString(),
        ];
    }
}
