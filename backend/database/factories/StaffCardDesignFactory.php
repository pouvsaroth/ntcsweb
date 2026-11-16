<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StaffCardDesign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StaffCardDesign> */
class StaffCardDesignFactory extends Factory
{
    protected $model = StaffCardDesign::class;

    public function definition(): array
    {
        return [
            'name' => 'Staff card',
            'width_mm' => 85.6,
            'height_mm' => 54,
            'front_fields' => [
                ['id' => 'f1', 'type' => 'full_name', 'x' => 30, 'y' => 20, 'w' => 50, 'h' => 6, 'font_size' => 10, 'color' => '#000000', 'bold' => true, 'align' => 'left'],
            ],
            'back_fields' => [],
        ];
    }
}
