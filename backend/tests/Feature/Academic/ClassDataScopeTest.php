<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Roles > Data view: a role set to "Assigned classes only" sees the classes the user teaches plus extra classes picked on the user. */
class ClassDataScopeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function limit(User $user): void
    {
        $user->roles()->update(['data_scope' => Role::SCOPE_ASSIGNED]);
        // Each web request starts fresh; in a test the same app is reused.
        app()->forgetScopedInstances();
    }

    private function names(): array
    {
        return collect($this->getJson('/api/v1/classes?per_page=100')->assertOk()->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_an_assigned_only_role_sees_its_teaching_classes_and_extra_classes(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW, Permissions::CLASSES_UPDATE]);
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $c1 = SchoolClass::factory()->create(['name' => 'Class 1']);
        $c2 = SchoolClass::factory()->create(['name' => 'Class 2', 'homeroom_staff_id' => $staff->id]);
        $c3 = SchoolClass::factory()->create(['name' => 'Class 3']);
        $c1->teachers()->attach($staff->id);

        // Role says All classes (the default): everything.
        $this->assertSame(['Class 1', 'Class 2', 'Class 3'], $this->names());

        $this->limit($user);
        $this->assertSame(['Class 1', 'Class 2'], $this->names());
        $this->getJson("/api/v1/classes/{$c3->id}")->assertForbidden();
        $this->getJson("/api/v1/classes/{$c1->id}")->assertOk();
    }

    public function test_extra_classes_are_picked_on_the_user_and_audited(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW, Permissions::USERS_VIEW, Permissions::USERS_UPDATE]);
        $admin->roles()->update(['level' => 80]);
        $c1 = SchoolClass::factory()->create(['name' => 'Class 1']);
        $c2 = SchoolClass::factory()->create(['name' => 'Class 2']);
        $teacherRole = Role::query()->where('tenant_id', $this->tenant->id)->where('slug', Role::TEACHER)->first()
            ?? Role::factory()->forTenant($this->tenant)->create(['name' => 'Teacher X', 'slug' => 'teacher-x', 'level' => 50]);
        $teacher = User::factory()->forTenant($this->tenant)->create();
        $teacher->attachRoles($teacherRole);
        $staff = Staff::factory()->create(['user_id' => $teacher->id]);
        $c1->teachers()->attach($staff->id);

        $this->putJson("/api/v1/users/{$teacher->id}/classes", ['class_ids' => [$c2->id]])
            ->assertOk()
            ->assertJsonPath('data.class_ids', [$c2->id])
            ->assertJsonPath('data.teaching_classes.0.name', 'Class 1')
            ->assertJsonPath('data.extra_classes.0.name', 'Class 2');
        $this->assertDatabaseHas('audit_logs', ['description' => "Changed extra classes for {$teacher->name}"]);
        $this->putJson("/api/v1/users/{$teacher->id}/classes", ['class_ids' => [999999]])->assertJsonValidationErrors('class_ids.0');

        // Nobody changes their own extra classes.
        $this->actingAsTenantUser($teacher->fresh());
        $this->putJson("/api/v1/users/{$teacher->id}/classes", ['class_ids' => [$c1->id, $c2->id]])->assertForbidden();

        // The teacher, limited, sees Class 1 (teaches) and Class 2 (extra).
        $teacherRole->update(['data_scope' => Role::SCOPE_ASSIGNED]);
        $teacherRole->permissions()->syncWithoutDetaching(\App\Models\Permission::query()->where('slug', Permissions::CLASSES_VIEW)->pluck('id'));
        $this->actingAsTenantUser($teacher->fresh());
        app()->forgetScopedInstances();
        $this->assertSame(['Class 1', 'Class 2'], $this->names());
    }

    public function test_the_role_form_saves_data_view_but_school_admin_always_sees_all(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ROLES_VIEW, Permissions::ROLES_CREATE, Permissions::ROLES_UPDATE])->roles()->update(['level' => 80]);

        $role = $this->postJson('/api/v1/roles', ['name' => 'Class teacher', 'level' => 10, 'data_scope' => 'assigned'])
            ->assertCreated()->assertJsonPath('data.data_scope', 'assigned')->json('data');
        $this->putJson("/api/v1/roles/{$role['id']}", ['data_scope' => 'all'])->assertOk()->assertJsonPath('data.data_scope', 'all');
        $this->putJson("/api/v1/roles/{$role['id']}", ['data_scope' => 'mine'])->assertJsonValidationErrors('data_scope');
    }

    public function test_students_and_enrollments_follow_the_users_classes(): void
    {
        $user = $this->actingAsAdminWithPermissions([
            Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE, Permissions::ENROLLMENTS_VIEW, Permissions::ENROLLMENTS_UPDATE, Permissions::CLASSES_VIEW,
        ]);
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $mine = SchoolClass::factory()->create(['name' => 'Mine']);
        $other = SchoolClass::factory()->create(['name' => 'Other']);
        $mine->teachers()->attach($staff->id);
        $dara = Student::factory()->create(['first_name' => 'Dara']);
        $lina = Student::factory()->create(['first_name' => 'Lina']);
        Student::factory()->create(['first_name' => 'NoClass']);
        $e1 = Enrollment::factory()->forStudent($dara)->forClass($mine)->create();
        $e2 = Enrollment::factory()->forStudent($lina)->forClass($other)->create();

        $this->getJson('/api/v1/students?per_page=100')->assertOk()->assertJsonCount(3, 'data');

        $this->limit($user);
        $names = collect($this->getJson('/api/v1/students?per_page=100')->assertOk()->json('data'))->pluck('first_name')->all();
        $this->assertSame(['Dara'], $names);
        $this->getJson("/api/v1/students/{$lina->id}")->assertForbidden();
        $this->getJson("/api/v1/students/{$lina->id}/record")->assertForbidden();
        $this->getJson("/api/v1/students/{$dara->id}")->assertOk();

        $ids = collect($this->getJson('/api/v1/enrollments?per_page=100')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$e1->id], $ids);
        $this->getJson("/api/v1/enrollments/{$e2->id}")->assertForbidden();
        $this->getJson("/api/v1/enrollments/{$e1->id}")->assertOk();
    }

    public function test_a_limited_user_cannot_enroll_or_bulk_add_into_someone_elses_class(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_VIEW]);
        $other = SchoolClass::factory()->create(['name' => 'Other']);
        $student = Student::factory()->create();
        $this->limit($user);

        $this->postJson("/api/v1/classes/{$other->id}/assign-students", ['student_ids' => [$student->id]])->assertForbidden();
        $this->postJson('/api/v1/enrollments/class-batch', ['class_id' => $other->id, 'student_ids' => [$student->id]])
            ->assertJsonValidationErrors('class_id');
    }

    public function test_attendance_follows_the_users_classes_and_a_substitute_covers_that_day(): void
    {
        $user = $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW, Permissions::ATTENDANCE_CREATE]);
        $staff = Staff::factory()->create(['user_id' => $user->id]);
        $away = Staff::factory()->create();
        $mine = SchoolClass::factory()->create(['name' => 'Mine']);
        $other = SchoolClass::factory()->create(['name' => 'Other']);
        $mine->teachers()->attach($staff->id);
        $e1 = Enrollment::factory()->forClass($mine)->create();
        $e2 = Enrollment::factory()->forClass($other)->create();
        $r1 = AttendanceRecord::factory()->forEnrollment($e1)->create();
        $r2 = AttendanceRecord::factory()->forEnrollment($e2)->create();
        $today = now()->toDateString();

        $this->limit($user);
        $this->assertSame([$r1->id], collect($this->getJson('/api/v1/attendance?per_page=100')->assertOk()->json('data'))->pluck('id')->all());
        $this->getJson("/api/v1/attendance/{$r2->id}")->assertForbidden();
        $this->getJson("/api/v1/classes/{$other->id}/attendance-summary")->assertForbidden();
        $this->getJson("/api/v1/attendance-summary?class_id={$other->id}")->assertForbidden();
        $all = collect($this->getJson('/api/v1/attendance-summary')->assertOk()->json('data'));
        $this->assertSame([$e1->id], $all->pluck('enrollment_id')->unique()->values()->all());
        $this->getJson("/api/v1/classes/{$other->id}/attendance-sheet?date_from={$today}&date_to={$today}")->assertForbidden();
        $this->getJson("/api/v1/classes/{$mine->id}/attendance?date={$today}")->assertOk();
        $this->getJson("/api/v1/classes/{$other->id}/attendance?date={$today}")->assertForbidden();

        // Covering Other today as a substitute: it counts as theirs today only.
        DB::connection('tenant')->table('teacher_substitutions')->insert([
            'class_id' => $other->id, 'absent_staff_id' => $away->id, 'substitute_staff_id' => $staff->id, 'date' => $today, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app()->forgetScopedInstances();
        $this->getJson("/api/v1/classes/{$other->id}/attendance?date={$today}")->assertOk();
        // Days later the cover is over: the class is gone again.
        $this->travel(2)->days();
        app()->forgetScopedInstances();
        $this->getJson("/api/v1/classes/{$other->id}/attendance?date=".now()->toDateString())->assertForbidden();
    }
}
