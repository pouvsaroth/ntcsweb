<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\GalleryImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GalleryImage>
 */
class GalleryImageFactory extends Factory
{
    protected $model = GalleryImage::class;

    public function definition(): array
    {
        return [
            'image_path' => 'tenants/0/gallery/'.fake()->uuid().'.jpg',
            'caption' => fake()->sentence(4),
            'sort_order' => fake()->numberBetween(0, 10),
            'status' => GalleryImage::STATUS_ACTIVE,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['status' => GalleryImage::STATUS_INACTIVE]);
    }
}
