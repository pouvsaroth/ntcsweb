<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Who may back up which database — see DatabaseBackupController. The dump
 * itself (pg_dump) isn't exercised here, only the access rules in front of it.
 */
class DatabaseBackupAccessTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_a_school_admin_only_sees_their_own_schools_database(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DATABASE_BACKUPS_DOWNLOAD]);
        Tenant::factory()->create(); // another school — must not be listed

        $response = $this->getJson('/api/v1/database-backups')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.type', 'tenant');
        $response->assertJsonPath('data.0.tenant_id', $this->tenant->id);
    }

    public function test_a_school_admin_cannot_back_up_the_central_or_another_schools_database(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DATABASE_BACKUPS_DOWNLOAD]);
        $otherSchool = Tenant::factory()->create();

        $this->getJson('/api/v1/database-backups/download?type=central')->assertForbidden();
        $this->getJson("/api/v1/database-backups/download?type=tenant&tenant_id={$otherSchool->id}")->assertForbidden();
    }

    public function test_backup_requires_the_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/database-backups')->assertForbidden();
        $this->getJson("/api/v1/database-backups/download?type=tenant&tenant_id={$this->tenant->id}")->assertForbidden();
    }

    public function test_a_super_admin_sees_the_central_database_and_every_active_school(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $role = Role::query()->platform()->where('slug', Role::SUPER_ADMIN)->first()
            ?? Role::factory()->platform()->system()->create([
                'slug' => Role::SUPER_ADMIN,
                'name' => 'Super Admin',
                'level' => Role::LEVELS[Role::SUPER_ADMIN],
            ]);
        $superAdmin = User::factory()->forTenant(null)->create();
        $superAdmin->attachRoles($role);
        $this->actingAsTenantUser($superAdmin);

        $response = $this->getJson('/api/v1/database-backups')->assertOk();

        $types = collect($response->json('data'))->pluck('type');
        $this->assertSame('central', $types->first());
        $this->assertContains($this->tenant->id, collect($response->json('data'))->pluck('tenant_id')->all());
    }
}
