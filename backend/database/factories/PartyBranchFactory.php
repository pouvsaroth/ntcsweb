<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PartyBranch;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PartyBranch> */
class PartyBranchFactory extends Factory
{
    protected $model = PartyBranch::class;

    public function definition(): array
    {
        return [
            'name' => fake()->city().' branch',
            'level' => 'province',
            'province_id' => fn () => Province::factory()->create()->id,
            'leader_name' => fake()->name(),
            'leader_phone' => fake()->numerify('0## ### ###'),
            'is_active' => true,
        ];
    }
}
