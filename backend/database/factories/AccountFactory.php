<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Support\Accounting\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('9###'),
            'name' => fake()->words(2, true),
            'type' => AccountType::EXPENSE,
            'parent_id' => null,
            'is_bank_or_cash' => false,
            'is_active' => true,
        ];
    }

    public function type(string $type): static
    {
        return $this->state(['type' => $type]);
    }

    public function bankOrCash(): static
    {
        return $this->state(['type' => AccountType::ASSET, 'is_bank_or_cash' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
