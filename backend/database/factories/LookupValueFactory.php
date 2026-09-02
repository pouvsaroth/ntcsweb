<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\LookupCategory;
use App\Models\LookupValue;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LookupValue>
 */
class LookupValueFactory extends Factory
{
    protected $model = LookupValue::class;

    public function definition(): array
    {
        return [
            'lookup_category_id' => LookupCategory::factory(),
            'code' => strtoupper(fake()->unique()->lexify('VALUE_???')),
            'is_active' => true,
            'sort_order' => 0,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (LookupValue $value) use ($tenant) {
            $value->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forCategory(LookupCategory $category): static
    {
        return $this->state(['lookup_category_id' => $category->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
