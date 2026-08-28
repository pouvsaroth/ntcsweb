<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'code' => mb_strtoupper(fake()->unique()->lexify('????')),
            'logo' => null,
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'address' => fake()->address(),
            'timezone' => 'Asia/Phnom_Penh',
            'locale' => 'en',
            'status' => Tenant::STATUS_ACTIVE,
            // See UserFactory: Model::shouldBeStrict() requires every nullable
            // column to be a present key, not merely absent.
            'settings' => null,
            'trial_ends_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Tenant::STATUS_SUSPENDED]);
    }
}
