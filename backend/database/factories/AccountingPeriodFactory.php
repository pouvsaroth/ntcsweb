<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountingPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountingPeriod>
 */
class AccountingPeriodFactory extends Factory
{
    protected $model = AccountingPeriod::class;

    public function definition(): array
    {
        return [
            'period' => now()->format('Y-m'),
            'closed_at' => now(),
        ];
    }

    public function forPeriod(string $period): static
    {
        return $this->state(['period' => $period]);
    }
}
