<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DiscountCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DiscountCode> */
class DiscountCodeFactory extends Factory
{
    protected $model = DiscountCode::class;

    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->bothify('CODE##??')),
            'name' => 'Promotion',
            'audience' => DiscountCode::AUDIENCE_PUBLIC,
            'discount_type' => DiscountCode::TYPE_PERCENT,
            'value' => 10,
            'currency' => 'USD',
            'starts_on' => now()->subDays(5)->toDateString(),
            'expires_on' => now()->addDays(30)->toDateString(),
            'status' => DiscountCode::STATUS_ACTIVE,
        ];
    }
}
