<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AssetCategory;
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

    public function forParent(AssetCategory $parent): static
    {
        return $this->state(['parent_id' => $parent->getKey()]);
    }
}
