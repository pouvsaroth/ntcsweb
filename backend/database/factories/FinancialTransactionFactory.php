<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\FinancialTransaction;
use App\Support\Accounting\TransactionType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FinancialTransaction>
 */
class FinancialTransactionFactory extends Factory
{
    protected $model = FinancialTransaction::class;

    public function definition(): array
    {
        return [
            'transaction_number' => 'TXN-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'transaction_date' => now()->toDateString(),
            'type' => TransactionType::INCOME,
            'debit_account_id' => Account::factory(),
            'credit_account_id' => Account::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
        ];
    }

    public function type(string $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function between(Account $debit, Account $credit): static
    {
        return $this->state(['debit_account_id' => $debit->getKey(), 'credit_account_id' => $credit->getKey()]);
    }
}
