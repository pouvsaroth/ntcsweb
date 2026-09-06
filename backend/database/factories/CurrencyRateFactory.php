<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CurrencyRate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CurrencyRate>
 */
class CurrencyRateFactory extends Factory
{
    protected $model = CurrencyRate::class;

    public function definition(): array
    {
        return [
            'effective_date' => fake()->unique()->dateTimeBetween('-1 year', 'now')->format('Y-m-d'),
            'khr_per_usd' => fake()->randomFloat(2, 4000, 4200),
        ];
    }

    /**
     * See TeacherFactory::forTenant()'s docblock for why this exists and when
     * it's actually needed (BelongsToTenant already auto-stamps tenant_id
     * from the ambient TenantContext on a plain create()).
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (CurrencyRate $rate) use ($tenant) {
            $rate->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
