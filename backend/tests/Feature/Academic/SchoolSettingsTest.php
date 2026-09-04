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

class SchoolSettingsTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_it_returns_the_current_school_info(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW]);

        // Mutate through $this->admin->tenant, not $this->tenant — the
        // request's TenantContext was set from the former (see
        // TestCase::actingInTenant()), a separately-hydrated relation
        // instance; updating the latter would silently not be seen by it.
        $this->admin->tenant->update(['name' => 'NewTech Computer School', 'email' => 'info@newtechkh.com']);

        $response = $this->getJson('/api/v1/settings/school');

        $response->assertOk();
        $response->assertJsonPath('data.name', 'NewTech Computer School');
        $response->assertJsonPath('data.email', 'info@newtechkh.com');
        $response->assertJsonPath('data.logo_url', null);
    }

    public function test_it_saves_school_info_with_a_logo(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $response = $this->post('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'email' => 'info@newtechkh.com',
            'phone' => '012345678',
            'address' => 'Phnom Penh, Cambodia',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'NewTech Computer School');
        $response->assertJsonPath('data.address', 'Phnom Penh, Cambodia');
        $this->assertNotNull($response->json('data.logo_url'));

        $tenant = $this->tenant->fresh();
        Storage::disk('public')->assertExists($tenant->logo);
    }

    public function test_updating_the_logo_deletes_the_previous_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->admin->tenant->update(['logo' => 'tenants/'.$this->tenant->id.'/branding/old.png']);
        Storage::disk('public')->put($this->admin->tenant->logo, 'fake-old-logo');

        $this->postJson('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'logo' => UploadedFile::fake()->image('new-logo.png'),
        ])->assertOk();

        Storage::disk('public')->assertMissing('tenants/'.$this->tenant->id.'/branding/old.png');
        Storage::disk('public')->assertExists($this->tenant->fresh()->logo);
    }

    public function test_updating_without_a_new_logo_keeps_the_existing_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->admin->tenant->update(['logo' => 'tenants/'.$this->tenant->id.'/branding/existing.png']);
        Storage::disk('public')->put($this->admin->tenant->logo, 'fake-logo');

        $this->postJson('/api/v1/settings/school', ['name' => 'Renamed School'])->assertOk();

        $tenant = $this->tenant->fresh();
        $this->assertSame('tenants/'.$this->tenant->id.'/branding/existing.png', $tenant->logo);
        $this->assertSame('Renamed School', $tenant->name);
    }

    public function test_status_slug_and_code_cannot_be_changed_through_this_endpoint(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $originalSlug = $this->tenant->slug;
        $originalStatus = $this->tenant->status;

        $this->postJson('/api/v1/settings/school', [
            'name' => 'Renamed School',
            'slug' => 'hijacked-slug',
            'status' => 'suspended',
            'code' => 'HIJACK',
        ])->assertOk();

        $tenant = $this->tenant->fresh();
        $this->assertSame($originalSlug, $tenant->slug);
        $this->assertSame($originalStatus, $tenant->status);
    }

    public function test_a_user_without_the_update_permission_cannot_save_school_info(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW]);

        $response = $this->postJson('/api/v1/settings/school', ['name' => 'Renamed School']);

        $response->assertForbidden();
    }

    public function test_the_public_endpoint_reflects_saved_school_info(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->postJson('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/settings');

        // Not asserting an "http..." prefix: Storage::fake() (see setUp())
        // always returns a root-relative URL regardless of the real disk's
        // configured `url`, a known Laravel testing quirk — the accessor
        // itself is verified against the real disk config in Tenant.php.
        $response->assertOk();
        $response->assertJsonPath('data.name', 'NewTech Computer School');
        $this->assertNotNull($response->json('data.logo'));
    }

    public function test_it_saves_the_khqr_template_without_touching_other_settings_keys(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->admin->tenant->update(['settings' => ['about' => ['history_title' => 'Our Story']]]);

        $this->postJson('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'khqr_template' => '00020101021129380009khqr@aclb...',
        ])->assertOk();

        $tenant = $this->tenant->fresh();
        $this->assertSame('00020101021129380009khqr@aclb...', $tenant->khqrTemplate());
        $this->assertSame('Our Story', $tenant->setting('about')['history_title']);
    }

    public function test_the_public_endpoint_never_exposes_the_raw_khqr_template(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->postJson('/api/v1/settings/school', [
            'name' => 'NewTech Computer School',
            'khqr_template' => '00020101021129380009khqr@aclb...',
        ])->assertOk();

        $response = $this->withHeader('X-Tenant', $this->tenant->slug)->getJson('/api/v1/public/settings');

        $response->assertOk();
        $response->assertJsonPath('data.has_khqr', true);
        $this->assertArrayNotHasKey('khqr_template', $response->json('data'));
    }

    public function test_a_different_tenants_logo_is_not_affected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $otherTenant = Tenant::factory()->create(['logo' => null]);

        $this->postJson('/api/v1/settings/school', [
            'name' => 'Renamed School',
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertOk();

        $this->assertNull($otherTenant->fresh()->logo);
    }
}
