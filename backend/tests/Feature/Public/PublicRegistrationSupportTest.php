<?php

declare(strict_types=1);

namespace Tests\Feature\Public;

use App\Models\SchoolClass;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * The read-only public endpoints the self-registration wizard needs beyond
 * what already existed: which classes offer a given course package, a
 * fixed-amount KHQR preview, and Cambodia's geography opened to the public
 * route group. None of these touch the admin Students/Enrollment area.
 */
class PublicRegistrationSupportTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    public function test_it_lists_only_classes_that_offer_the_given_package(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->setUpAcademicCatalog();

        $otherClass = SchoolClass::factory()->forProgram($this->computerProgram)->create(['name' => 'Not Offering It']);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson("/api/v1/public/course-packages/{$this->msWordPackage->id}/classes");

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Computer Evening A'));
        $this->assertFalse($names->contains($otherClass->name));
    }

    public function test_khqr_preview_fails_cleanly_when_not_configured(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/public/khqr-preview?amount=24.00&currency=USD');

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'KHQR_NOT_CONFIGURED');
    }

    public function test_khqr_preview_returns_a_fixed_amount_code_once_configured(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->postJson('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'khqr_template' => '00020101021129380009khqr@aclb0111855100589230206ACLEDA391300042CCY010145204599953031165802KH5910ពៅ  សារដ្ធ6010PHNOM PENH621302090122374226304D1E3',
        ])->assertOk();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)
            ->getJson('/api/v1/public/khqr-preview?amount=24.00&currency=USD');

        $response->assertOk();
        $this->assertStringContainsString('540524.00', $response->json('data.khqr_string'));
        $this->assertStringContainsString('5303840', $response->json('data.khqr_string'));
    }

    public function test_provinces_are_reachable_without_authentication(): void
    {
        // Deliberately no actingAsAdminWithPermissions()/login of any kind —
        // this is the whole point of the test.
        $tenant = Tenant::factory()->create();

        $response = $this->withHeader('X-Tenant', $tenant->slug)->getJson('/api/v1/public/geo/provinces');

        $response->assertOk();
    }
}
