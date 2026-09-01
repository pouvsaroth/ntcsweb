<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Tenant;
use App\Support\Assets\AssetCondition;
use App\Support\Assets\AssetStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Asset>
 */
class AssetFactory extends Factory
{
    protected $model = Asset::class;

    public function definition(): array
    {
        return [
            'asset_number' => 'AST-'.fake()->unique()->numerify('######'),
            'category_id' => AssetCategory::factory(),
            'name' => fake()->words(2, true),
            'brand' => fake()->company(),
            'model' => fake()->bothify('??-####'),
            'purchase_date' => fake()->dateTimeBetween('-2 years', 'now')->format('Y-m-d'),
            'purchase_price' => fake()->randomFloat(2, 50, 2000),
            'status' => AssetStatus::IN_STOCK,
            'condition' => AssetCondition::NEW,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Asset $asset) use ($tenant) {
            $asset->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forCategory(AssetCategory $category): static
    {
        return $this->state(['category_id' => $category->getKey()]);
    }

    public function status(string $status): static
    {
        return $this->state(['status' => $status]);
    }

    public function condition(string $condition): static
    {
        return $this->state(['condition' => $condition]);
    }
}
