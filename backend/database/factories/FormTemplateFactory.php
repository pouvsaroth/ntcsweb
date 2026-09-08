<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormCategory;
use App\Models\FormTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 */
class FormTemplateFactory extends Factory
{
    protected $model = FormTemplate::class;

    public function definition(): array
    {
        return [
            'form_category_id' => FormCategory::factory(),
            'code' => 'TT-'.strtoupper(fake()->unique()->lexify('???')).'-FM-001',
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'order' => 0,
            'is_active' => true,
        ];
    }
}
