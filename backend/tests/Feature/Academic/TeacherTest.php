<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Teacher;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class TeacherTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_lists_teachers_for_the_current_tenant_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW]);

        Teacher::factory()->count(3)->create();
        $this->createForOtherTenant(fn () => Teacher::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->getJson('/api/v1/teachers');

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
    }

    public function test_it_creates_a_teacher(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_CREATE]);

        $response = $this->postJson('/api/v1/teachers', [
            'employee_code' => 'T-0001',
            'name' => 'Sok Dara',
            'email' => 'dara@newtech.test',
            'specialization' => 'Web Development',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.employee_code', 'T-0001');
        $this->assertDatabaseHas('teachers', ['employee_code' => 'T-0001', 'tenant_id' => $this->tenant->id]);
    }

    /**
     * Regression guard: `status` is not in the request above, so it comes
     * entirely from the column's DB default. Eloquent's create() only sends
     * columns actually set on the model, so a model without a PHP-level
     * mirror of that default would report `status: null` in this very
     * response despite the row being correctly stored as 'active' —
     * confirmed live against a running server before this test was added.
     */
    public function test_a_newly_created_teacher_reports_its_default_status_immediately(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_CREATE]);

        $response = $this->postJson('/api/v1/teachers', ['employee_code' => 'T-0002', 'name' => 'Sok Dara']);

        $response->assertJsonPath('data.status', Teacher::STATUS_ACTIVE);
    }

    public function test_employee_code_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_CREATE]);
        Teacher::factory()->create(['employee_code' => 'T-0001']);

        $response = $this->postJson('/api/v1/teachers', [
            'employee_code' => 'T-0001',
            'name' => 'Another Teacher',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('employee_code');
    }

    /**
     * The same employee code at a different school must not collide —
     * uniqueness is scoped per tenant, not global.
     */
    public function test_employee_code_may_repeat_across_tenants(): void
    {
        $this->createForOtherTenant(
            fn () => Teacher::factory()->forTenant(Tenant::factory()->create())->create(['employee_code' => 'T-0001']),
        );

        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_CREATE]);

        $response = $this->postJson('/api/v1/teachers', [
            'employee_code' => 'T-0001',
            'name' => 'Sok Dara',
        ]);

        $response->assertCreated();
    }

    public function test_it_updates_a_teacher(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_UPDATE]);
        $teacher = Teacher::factory()->create(['name' => 'Old Name']);

        $response = $this->putJson("/api/v1/teachers/{$teacher->id}", ['name' => 'New Name']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'New Name');
    }

    public function test_it_soft_deletes_a_teacher(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_DELETE]);
        $teacher = Teacher::factory()->create();

        $response = $this->deleteJson("/api/v1/teachers/{$teacher->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted('teachers', ['id' => $teacher->id]);
    }

    public function test_a_user_without_permission_cannot_create_a_teacher(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW]);

        $response = $this->postJson('/api/v1/teachers', ['employee_code' => 'T-0001', 'name' => 'Someone']);

        $response->assertForbidden();
    }

    /**
     * A teacher belonging to another school must be completely unreachable —
     * not just filtered from listings, but 404 on direct access by id.
     */
    public function test_a_teacher_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW]);
        $otherTeacher = $this->createForOtherTenant(fn () => Teacher::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->getJson("/api/v1/teachers/{$otherTeacher->id}");

        $response->assertNotFound();
    }
}
