<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\CoursePackage;
use App\Models\Tenant;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    protected $model = Video::class;

    public function definition(): array
    {
        return [
            'course_package_id' => CoursePackage::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(12),
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'sort_order' => fake()->numberBetween(0, 10),
            'status' => Video::STATUS_ACTIVE,
        ];
    }

    public function forTenant(Tenant|int $tenant): static
    {
        return $this->afterMaking(function (Video $video) use ($tenant) {
            $video->forceFill([
                'tenant_id' => $tenant instanceof Tenant ? $tenant->getKey() : $tenant,
            ]);
        });
    }

    public function forPackage(CoursePackage $package): static
    {
        return $this->state(['course_package_id' => $package->getKey()]);
    }

    public function inactive(): static
    {
        return $this->state(['status' => Video::STATUS_INACTIVE]);
    }
}
