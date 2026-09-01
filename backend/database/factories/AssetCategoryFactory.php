<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetCategory;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssetCategory>
 */
class AssetCategoryFactory extends Factory
{
    protected $model = AssetCategory::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('CAT???')),
            'name' => fake()->words(2, true),
            'is_active' => true,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (AssetCategory $category) use ($tenant) {
            $category->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forParent(AssetCategory $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }
}
