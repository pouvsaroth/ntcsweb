<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AboutPageTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_returns_an_empty_fixed_shape_before_any_content_is_saved(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW]);

        $response = $this->getJson('/api/v1/settings/about');

        $response->assertOk();
        $response->assertJsonCount(4, 'data.stats');
        $response->assertJsonCount(3, 'data.pillars');
        $response->assertJsonCount(4, 'data.achievements');
        $response->assertJsonPath('data.history_title', '');
        $response->assertJsonPath('data.history_image_url', null);
    }

    public function test_it_saves_about_content_with_a_history_image(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $response = $this->post('/api/v1/settings/about', [
            'history_title' => 'ប្រវត្តិសាលា',
            'history_paragraph_1' => 'First paragraph.',
            'history_paragraph_2' => 'Second paragraph.',
            'history_image' => UploadedFile::fake()->image('history.jpg'),
            'stats' => [
                ['value' => '10+', 'label' => 'Years'],
                ['value' => '500+', 'label' => 'Students'],
                ['value' => '15+', 'label' => 'Teachers'],
                ['value' => '20+', 'label' => 'Programs'],
            ],
            'pillars' => [
                ['icon' => '🔭', 'title' => 'Vision', 'description' => 'Our vision.'],
                ['icon' => '🎯', 'title' => 'Mission', 'description' => 'Our mission.'],
                ['icon' => '🌟', 'title' => 'Goals', 'description' => 'Our goals.'],
            ],
            'achievements_title' => 'Our Achievements',
            'achievements' => [
                ['icon' => '🥇', 'value' => '1st', 'label' => 'Award'],
                ['icon' => '🏅', 'value' => '3x', 'label' => 'Best School'],
                ['icon' => '📜', 'value' => '100+', 'label' => 'Certificates'],
                ['icon' => '🤝', 'value' => '20+', 'label' => 'Partners'],
            ],
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonPath('data.history_title', 'ប្រវត្តិសាលា');
        $response->assertJsonPath('data.stats.0.value', '10+');
        $response->assertJsonPath('data.pillars.1.title', 'Mission');
        $response->assertJsonPath('data.achievements.3.label', 'Partners');
        $this->assertNotNull($response->json('data.history_image_url'));

        $tenant = $this->tenant->fresh();
        Storage::disk('public')->assertExists($tenant->settings['about']['history_image_path']);
    }

    public function test_stats_must_be_exactly_four(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $response = $this->postJson('/api/v1/settings/about', $this->validPayload([
            'stats' => [['value' => '10+', 'label' => 'Years']],
        ]));

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('stats');
    }

    public function test_a_user_without_the_update_permission_cannot_save_about_content(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW]);

        $response = $this->postJson('/api/v1/settings/about', $this->validPayload());

        $response->assertForbidden();
    }

    public function test_saving_about_content_does_not_clobber_other_tenant_settings(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->tenant->update(['settings' => ['public_site' => ['hero_title' => 'Keep me']]]);

        $this->postJson('/api/v1/settings/about', $this->validPayload())->assertOk();

        $settings = $this->tenant->fresh()->settings;
        $this->assertSame('Keep me', $settings['public_site']['hero_title']);
        $this->assertArrayHasKey('about', $settings);
    }

    public function test_the_public_endpoint_omits_about_content_until_configured(): void
    {
        $tenant = Tenant::factory()->create();
        $this->actingInTenant($tenant);

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/settings');

        $response->assertOk();
        $response->assertJsonPath('data.about', null);
    }

    public function test_the_public_endpoint_exposes_about_content_once_configured_for_that_tenant_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->postJson('/api/v1/settings/about', $this->validPayload(['history_title' => 'Our Story']))->assertOk();

        $otherTenant = Tenant::factory()->create();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/settings');
        $response->assertJsonPath('data.about.history_title', 'Our Story');

        $otherResponse = $this->withHeader('X-Tenant', $otherTenant->slug)->getJson('/api/v1/public/settings');
        $otherResponse->assertJsonPath('data.about', null);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return [
            'history_title' => 'History',
            'history_paragraph_1' => 'Paragraph one.',
            'history_paragraph_2' => 'Paragraph two.',
            'stats' => [
                ['value' => '10+', 'label' => 'Years'],
                ['value' => '500+', 'label' => 'Students'],
                ['value' => '15+', 'label' => 'Teachers'],
                ['value' => '20+', 'label' => 'Programs'],
            ],
            'pillars' => [
                ['icon' => '🔭', 'title' => 'Vision', 'description' => 'Our vision.'],
                ['icon' => '🎯', 'title' => 'Mission', 'description' => 'Our mission.'],
                ['icon' => '🌟', 'title' => 'Goals', 'description' => 'Our goals.'],
            ],
            'achievements_title' => 'Our Achievements',
            'achievements' => [
                ['icon' => '🥇', 'value' => '1st', 'label' => 'Award'],
                ['icon' => '🏅', 'value' => '3x', 'label' => 'Best School'],
                ['icon' => '📜', 'value' => '100+', 'label' => 'Certificates'],
                ['icon' => '🤝', 'value' => '20+', 'label' => 'Partners'],
            ],
            ...$overrides,
        ];
    }
}
