<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Fund;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Fund> */
class FundFactory extends Factory
{
    protected $model = Fund::class;

    public function definition(): array
    {
        return [
            'name' => 'Building fund',
            'fund_type' => 'BUILDING',
            'currency' => 'USD',
            'opened_on' => now()->toDateString(),
            'status' => 'active',
        ];
    }
}
