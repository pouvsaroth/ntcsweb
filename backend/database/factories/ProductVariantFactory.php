<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductVariant>
 */
class ProductVariantFactory extends Factory
{
    protected $model = ProductVariant::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'name' => fake()->randomElement(['S', 'M', 'L', 'XL', 'XXL']),
            'price_override' => null,
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (ProductVariant $variant) use ($tenant) {
            $variant->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }
}
