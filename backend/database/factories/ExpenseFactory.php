<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Tenant;
use App\Support\Accounting\ExpenseStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Expense>
 */
class ExpenseFactory extends Factory
{
    protected $model = Expense::class;

    public function definition(): array
    {
        return [
            'expense_number' => 'EXP-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'expense_date' => now()->toDateString(),
            'account_id' => Account::factory(),
            'amount' => fake()->randomFloat(2, 10, 500),
            'vendor' => fake()->company(),
            'description' => fake()->sentence(),
            'status' => ExpenseStatus::PENDING_APPROVAL,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Expense $expense) use ($tenant) {
            $expense->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forAccount(Account $account): static
    {
        return $this->state(['account_id' => $account->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }
}
