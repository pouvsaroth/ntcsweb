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
        $this->actingAsAdminWithPermissions([]);
        $this->tenant->update(['settings' => ['about' => $this->validPayload(['history_title' => 'Our Story'])]]);

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
