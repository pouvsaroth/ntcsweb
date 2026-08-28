<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\HomeSlide;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HomeSlide>
 */
class HomeSlideFactory extends Factory
{
    protected $model = HomeSlide::class;

    public function definition(): array
    {
        return [
            'image_path' => 'tenants/0/home-slides/'.fake()->uuid().'.jpg',
            'title' => fake()->sentence(4),
            'subtitle' => fake()->sentence(8),
            'link_url' => null,
            'sort_order' => fake()->numberBetween(0, 10),
            'status' => HomeSlide::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (HomeSlide $slide) use ($tenant) {
            $slide->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function inactive(): static
    {
        return $this->state(['status' => HomeSlide::STATUS_INACTIVE]);
    }
}
