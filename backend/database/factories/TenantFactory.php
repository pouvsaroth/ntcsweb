<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Support\Tenancy\SchoolDefaults;
use App\Support\Tenancy\TenantContext;
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

    /**
     * Every test school starts with its own default rows, as a real one does
     * when it is set up (SchoolDefaults). They used to come from migrations
     * and be shared by every test school; row-level security now keeps each
     * school to its own rows, so each needs its own copy.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Tenant $tenant) {
            app(TenantContext::class)->runFor($tenant, fn () => SchoolDefaults::seed());
        });
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => Tenant::STATUS_SUSPENDED]);
    }
}
