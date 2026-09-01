<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AccountingPeriod;
use App\Models\Tenant;
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

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AccountingPeriod $period) use ($tenant) {
            $period->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forPeriod(string $period): static
    {
        return $this->state(['period' => $period]);
    }
}
