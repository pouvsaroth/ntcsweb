<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\ParentStudent;
use App\Models\Role;
use App\Models\Student;
use App\Models\StudentGuardian;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Students > Guardians > Give parent access (Parent portal, step 1). */
class ParentAccessTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function guardian(Student $student, array $overrides = []): StudentGuardian
    {
        return StudentGuardian::query()->create($overrides + [
            'student_id' => $student->id, 'guardian_name' => 'Sok Mother', 'guardian_type' => 'Mother', 'phone' => '012 345 678',
        ]);
    }

    public function test_staff_give_a_guardian_a_login_and_siblings_share_it(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        [$dara, $sophea] = Student::factory()->count(2)->create();
        $mother = $this->guardian($dara);
        $this->guardian($sophea, ['phone' => '012345678', 'guardian_type' => 'Mother']);
        $this->guardian($dara, ['guardian_name' => 'Sok Father', 'guardian_type' => 'Father', 'phone' => '099 111 222']);

        $res = $this->postJson("/api/v1/students/{$dara->id}/parent-access", ['guardian_id' => $mother->id])->assertOk()->json('data');
        $this->assertTrue($res['new_login']);
        $this->assertSame('012345678', $res['temporary_password']);
        $this->assertSame(2, $res['children'], 'the sibling with the same guardian phone is linked too');

        $parent = User::query()->findOrFail($res['parent']['id']);
        $this->assertTrue($parent->hasRole(Role::PARENT));
        $this->assertSame($admin->tenant_id, $parent->tenant_id);
        $this->assertEqualsCanonicalizing([$dara->id, $sophea->id], ParentStudent::childIdsOf($parent->id));

        // The parent signs in with the phone and the temporary password.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['login' => '012 345 678', 'password' => '012345678', 'device_name' => 'Parent phone'])
            ->assertOk()->assertJsonPath('data.user.id', $parent->id);

        // The sibling's page shows the same login; no second password.
        $this->actingAs($admin);
        $again = $this->postJson("/api/v1/students/{$sophea->id}/parent-access", ['guardian_id' => StudentGuardian::query()->where('student_id', $sophea->id)->value('id')])->assertOk()->json('data');
        $this->assertFalse($again['new_login']);
        $this->assertNull($again['temporary_password']);
        $this->assertSame(1, User::query()->where('phone', '012345678')->count());

        $overview = $this->getJson("/api/v1/students/{$dara->id}/parent-access")->assertOk()->json('data');
        $this->assertSame($parent->id, $overview['guardians'][0]['parent']['id']);
        $this->assertSame(2, $overview['guardians'][0]['parent']['children_count']);
        $this->assertNull($overview['guardians'][1]['parent'], 'the father has no login yet');
    }

    public function test_removing_the_last_child_switches_a_parent_only_login_off(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create();
        $id = $this->postJson("/api/v1/students/{$student->id}/parent-access", ['guardian_id' => $this->guardian($student)->id])->json('data.parent.id');

        $this->postJson("/api/v1/students/{$student->id}/parent-access/{$id}/reset-password")->assertOk()->assertJsonPath('data.temporary_password', '012345678');
        $this->deleteJson("/api/v1/students/{$student->id}/parent-access/{$id}")->assertOk();
        $this->assertSame(User::STATUS_INACTIVE, User::query()->find($id)->status);
        $this->assertSame([], ParentStudent::childIdsOf($id));
    }

    public function test_a_staff_member_who_is_a_parent_keeps_one_login(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        $admin->forceFill(['phone' => '016777888'])->save();
        $student = Student::factory()->create();
        $guardian = $this->guardian($student, ['phone' => '016 777 888']);

        $res = $this->postJson("/api/v1/students/{$student->id}/parent-access", ['guardian_id' => $guardian->id])->assertOk()->json('data');
        $this->assertSame($admin->id, $res['parent']['id']);
        $this->assertNull($res['temporary_password']);
        $this->assertTrue($admin->fresh()->hasRole(Role::PARENT));

        // Removing the child takes the Parent role away but never switches the staff login off.
        $this->deleteJson("/api/v1/students/{$student->id}/parent-access/{$admin->id}")->assertOk();
        $this->assertSame(User::STATUS_ACTIVE, $admin->fresh()->status);
        $this->assertFalse($admin->fresh()->hasRole(Role::PARENT));
    }

    public function test_a_view_only_user_cannot_give_access(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $student = Student::factory()->create();
        $this->getJson("/api/v1/students/{$student->id}/parent-access")->assertOk();
        $this->postJson("/api/v1/students/{$student->id}/parent-access", ['guardian_id' => $this->guardian($student)->id])->assertForbidden();
    }

    public function test_a_parent_sees_only_their_own_children_and_only_looks(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        [$dara, $sophea, $other] = Student::factory()->count(3)->create();
        $this->guardian($sophea);
        $id = $this->postJson("/api/v1/students/{$dara->id}/parent-access", ['guardian_id' => $this->guardian($dara)->id])->json('data.parent.id');
        $parent = User::query()->findOrFail($id);

        $this->actingAs($parent);
        $children = $this->getJson('/api/v1/my-children')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing([$dara->id, $sophea->id], array_column($children, 'id'));

        // Two children: the portal must say which one.
        $this->getJson('/api/v1/my-invoices')->assertJsonValidationErrors('child');
        $this->withHeader('X-Child', (string) $sophea->id)->getJson('/api/v1/my-invoices')->assertOk();
        $this->withHeader('X-Child', (string) $sophea->id)->getJson('/api/v1/my-attendance')->assertOk();
        $this->withHeader('X-Child', (string) $sophea->id)->getJson('/api/v1/my-scores')->assertOk();
        // Not their child.
        $this->withHeader('X-Child', (string) $other->id)->getJson('/api/v1/my-invoices')->assertForbidden();
        // Looking only (a leave request is the one thing a parent may send).
        $this->withHeader('X-Child', (string) $sophea->id)->postJson('/api/v1/my-class-change-requests', [])->assertStatus(422);
        $invoice = \App\Models\Invoice::factory()->create(['student_id' => $sophea->id]);
        $this->withHeader('X-Child', (string) $sophea->id)->postJson("/api/v1/my-invoices/{$invoice->id}/discount-code", ['discount_code' => 'X'])->assertForbidden();
        $this->withHeader('X-Child', (string) $sophea->id)->getJson('/api/v1/my-leave-requests/enrollments')->assertOk();
    }

    public function test_a_parent_with_one_child_needs_no_choice(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create();
        $id = $this->postJson("/api/v1/students/{$student->id}/parent-access", ['guardian_id' => $this->guardian($student)->id])->json('data.parent.id');

        $this->actingAs(User::query()->findOrFail($id));
        $this->getJson('/api/v1/my-invoices')->assertOk();
        $this->getJson('/api/v1/my-attendance')->assertOk();
    }
}
