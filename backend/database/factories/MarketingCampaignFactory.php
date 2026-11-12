<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MarketingCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MarketingCampaign> */
class MarketingCampaignFactory extends Factory
{
    protected $model = MarketingCampaign::class;

    public function definition(): array
    {
        return [
            'name' => 'New term Facebook ads',
            'channel' => 'FACEBOOK',
            'start_on' => now()->toDateString(),
            'currency' => 'USD',
            'budget' => 200,
            'status' => 'active',
        ];
    }
}
