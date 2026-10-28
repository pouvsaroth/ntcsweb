<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdatePreferencesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->forTenant($tenant)->create(['locale' => 'en']);
        $this->actingAsTenantUser($this->user);
    }

    public function test_it_saves_each_choice_and_keeps_the_others(): void
    {
        $this->patchJson('/api/v1/auth/me/preferences', ['theme' => 'dark'])->assertOk()
            ->assertJsonPath('data.preferences.theme', 'dark');

        $this->patchJson('/api/v1/auth/me/preferences', ['accent' => '#38BDF8', 'clock' => '12'])->assertOk()
            ->assertJsonPath('data.preferences.theme', 'dark')
            ->assertJsonPath('data.preferences.accent', '#38bdf8')
            ->assertJsonPath('data.preferences.clock', '12');

        $this->patchJson('/api/v1/auth/me/preferences', ['locale' => 'km'])->assertOk()
            ->assertJsonPath('data.locale', 'km');

        $fresh = $this->user->fresh();
        $this->assertSame('km', $fresh->locale);
        $this->assertSame(['theme' => 'dark', 'accent' => '#38bdf8', 'clock' => '12', 'locale' => 'km'], $fresh->preferences);
    }

    public function test_a_null_accent_goes_back_to_the_school_colour(): void
    {
        $this->user->update(['preferences' => ['accent' => '#38bdf8']]);

        $this->patchJson('/api/v1/auth/me/preferences', ['accent' => null])->assertOk()
            ->assertJsonPath('data.preferences.accent', null);
    }

    public function test_it_rejects_unknown_values(): void
    {
        $this->patchJson('/api/v1/auth/me/preferences', [
            'theme' => 'purple',
            'accent' => 'red',
            'clock' => '13',
            'locale' => 'fr',
        ])->assertUnprocessable()->assertJsonValidationErrors(['theme', 'accent', 'clock', 'locale']);
    }

    public function test_me_includes_preferences(): void
    {
        $this->user->update(['preferences' => ['theme' => 'light']]);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.preferences.theme', 'light');
    }
}
