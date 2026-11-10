<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CustomerLoan;
use App\Models\LoanCustomer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerLoan> */
class CustomerLoanFactory extends Factory
{
    protected $model = CustomerLoan::class;

    public function definition(): array
    {
        return [
            'loan_customer_id' => LoanCustomer::factory(),
            'amount' => 1000,
            'currency' => 'USD',
            'lent_on' => now()->toDateString(),
            'interest_type' => 'none',
            'status' => 'active',
        ];
    }
}
