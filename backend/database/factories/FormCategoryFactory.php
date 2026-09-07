<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormCategory>
 */
class FormCategoryFactory extends Factory
{
    protected $model = FormCategory::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'order' => 0,
            'is_active' => true,
        ];
    }

    /**
     * See CurrencyRateFactory::forTenant()'s docblock for why this exists.
     */
    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (FormCategory $category) use ($tenant) {
            $category->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
