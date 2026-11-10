<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LoanCustomer;
use App\Models\SavingsAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SavingsAccount> */
class SavingsAccountFactory extends Factory
{
    protected $model = SavingsAccount::class;

    public function definition(): array
    {
        return [
            'loan_customer_id' => LoanCustomer::factory(),
            'currency' => 'USD',
            'opened_on' => now()->toDateString(),
            'status' => 'open',
        ];
    }
}
