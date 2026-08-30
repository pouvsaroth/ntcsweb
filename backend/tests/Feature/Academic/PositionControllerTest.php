<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Position;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class PositionControllerTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_lists_positions_for_the_current_tenant_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::POSITIONS_VIEW]);

        Position::factory()->count(2)->create();
        $this->createForOtherTenant(fn () => Position::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->getJson('/api/v1/positions');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
    }

    public function test_it_creates_a_position_carrying_a_role(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::POSITIONS_CREATE]);
        $role = Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant']);

        $response = $this->postJson('/api/v1/positions', [
            'name' => 'Accountant',
            'role_id' => $role->id,
            'description' => 'Handles accounting and financial tasks',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Accountant');
        $response->assertJsonPath('data.role.id', $role->id);
        $this->assertDatabaseHas('positions', ['name' => 'Accountant', 'role_id' => $role->id, 'tenant_id' => $this->tenant->id]);
    }

    public function test_position_name_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::POSITIONS_CREATE]);
        $role = Role::factory()->forTenant($this->tenant)->create();
        Position::factory()->create(['name' => 'Accountant', 'role_id' => $role->id]);

        $response = $this->postJson('/api/v1/positions', ['name' => 'Accountant', 'role_id' => $role->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('name');
    }

    public function test_a_role_from_another_tenant_cannot_back_a_position(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::POSITIONS_CREATE]);
        $otherRole = $this->createForOtherTenant(fn () => Role::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->postJson('/api/v1/positions', ['name' => 'Accountant', 'role_id' => $otherRole->id]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('role_id');
    }

    public function test_a_position_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::POSITIONS_VIEW]);
        $other = $this->createForOtherTenant(fn () => Position::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/positions/{$other->id}")->assertNotFound();
    }
}
