<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class GeneralSettingsControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_defaults_to_nts_when_unset(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_VIEW]);

        $response = $this->getJson('/api/v1/settings/general');

        $response->assertOk();
        $response->assertJsonPath('data.student_id_prefix', 'NTS');
    }

    public function test_it_updates_the_prefix(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE, Permissions::TENANT_SETTINGS_VIEW]);

        $response = $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'ABC']);

        $response->assertOk();
        $response->assertJsonPath('data.student_id_prefix', 'ABC');
        $this->assertSame('ABC', $this->tenant->fresh()->setting('student_id_prefix'));
    }

    public function test_it_normalizes_a_lowercase_prefix_to_uppercase(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $response = $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'nts']);

        $response->assertOk();
        $response->assertJsonPath('data.student_id_prefix', 'NTS');
    }

    public function test_it_trims_surrounding_whitespace(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $response = $this->postJson('/api/v1/settings/general', ['student_id_prefix' => '  NTS  ']);

        $response->assertOk();
        $response->assertJsonPath('data.student_id_prefix', 'NTS');
    }

    public function test_a_blank_prefix_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id_prefix');

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => '   '])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id_prefix');
    }

    public function test_a_prefix_with_an_inner_space_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'NTS 123'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('student_id_prefix');
    }

    public function test_a_user_without_the_permission_cannot_change_the_prefix(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);

        $this->postJson('/api/v1/settings/general', ['student_id_prefix' => 'ABC'])->assertForbidden();
    }
}
