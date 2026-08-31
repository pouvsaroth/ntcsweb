<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\AuditLog;
use App\Models\Position;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Audit\AuditAction;
use App\Support\Audit\AuditLogger;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_creating_a_student_writes_a_create_audit_log(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $response = $this->postJson('/api/v1/students', [
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'phone' => '012345678',
        ]);

        $response->assertCreated();
        $studentId = $response->json('data.id');

        $log = AuditLog::where('auditable_type', Student::class)->where('auditable_id', $studentId)->firstOrFail();

        $this->assertSame(AuditAction::CREATE, $log->action);
        $this->assertSame('Students', $log->module);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertNotNull($log->new_values);
        $this->assertSame('Dara', $log->new_values['first_name']);
        $this->assertStringContainsString('Created student', $log->description);
    }

    public function test_updating_a_students_phone_records_only_that_field(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create(['phone' => '012345678', 'first_name' => 'Dara', 'last_name' => 'Sok']);

        $this->putJson("/api/v1/students/{$student->id}", [
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'phone' => '098765432',
        ])->assertOk();

        $log = AuditLog::where('auditable_type', Student::class)
            ->where('auditable_id', $student->id)
            ->where('action', AuditAction::UPDATE)
            ->firstOrFail();

        // Only the field that actually changed — not first_name/last_name,
        // which were resubmitted unchanged.
        $this->assertSame(['012345678'], array_values($log->old_values));
        $this->assertSame(['098765432'], array_values($log->new_values));
        $this->assertArrayHasKey('phone', $log->old_values);
        $this->assertArrayHasKey('phone', $log->new_values);
    }

    public function test_deleting_a_student_records_the_old_values(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_DELETE]);
        $student = Student::factory()->create(['student_code' => 'NTS-000001']);

        $this->deleteJson("/api/v1/students/{$student->id}")->assertNoContent();

        $log = AuditLog::where('auditable_type', Student::class)
            ->where('auditable_id', $student->id)
            ->where('action', AuditAction::DELETE)
            ->firstOrFail();

        $this->assertSame('NTS-000001', $log->old_values['student_code']);
        $this->assertNull($log->new_values);
    }

    public function test_changing_a_students_status_is_recorded_as_a_status_change_not_a_generic_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create(['status' => Student::STATUS_ACTIVE]);

        $this->putJson("/api/v1/students/{$student->id}", ['status' => Student::STATUS_INACTIVE])->assertOk();

        $log = AuditLog::where('auditable_type', Student::class)->where('auditable_id', $student->id)->latest('id')->firstOrFail();

        $this->assertSame(AuditAction::STATUS_CHANGE, $log->action);
        $this->assertSame(Student::STATUS_ACTIVE, $log->old_values['status']);
        $this->assertSame(Student::STATUS_INACTIVE, $log->new_values['status']);
    }

    public function test_changing_staff_position_writes_a_position_change_and_a_user_role_change(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::STAFF_UPDATE, Permissions::STAFF_CREATE]);

        $accountant = \App\Models\Role::factory()->forTenant($this->tenant)->create(['name' => 'Accountant']);
        $hrManager = \App\Models\Role::factory()->forTenant($this->tenant)->create(['name' => 'HR Manager']);
        $accountantPosition = Position::factory()->create(['name' => 'Accountant', 'role_id' => $accountant->id]);
        $hrPosition = Position::factory()->create(['name' => 'HR Manager', 'role_id' => $hrManager->id]);

        $staff = Staff::factory()->create(['position_id' => $accountantPosition->id]);
        $staffUser = \App\Models\User::factory()->forTenant($this->tenant)->create();
        $staffUser->attachRoles($accountant);
        $staff->forceFill(['user_id' => $staffUser->id])->save();

        $this->putJson("/api/v1/staff/{$staff->id}", ['position_id' => $hrPosition->id])->assertOk();

        $positionLog = AuditLog::where('auditable_type', Staff::class)
            ->where('auditable_id', $staff->id)
            ->where('action', AuditAction::POSITION_CHANGE)
            ->firstOrFail();

        $this->assertStringContainsString('Accountant', $positionLog->description);
        $this->assertStringContainsString('HR Manager', $positionLog->description);

        $roleLog = AuditLog::where('auditable_type', \App\Models\User::class)
            ->where('auditable_id', $staffUser->id)
            ->where('action', AuditAction::ROLE_CHANGE)
            ->firstOrFail();

        $this->assertSame('Changed user role from Accountant to HR Manager', $roleLog->description);
        $this->assertSame($admin->id, $roleLog->user_id);
    }

    public function test_successful_login_writes_a_login_audit_log(): void
    {
        $user = $this->actingAsAdminWithPermissions([]);
        $user->forceFill(['password' => 'CorrectHorse1!'])->saveQuietly();

        // A device_name takes the token path (AuthController::tokenResponse()),
        // sidestepping the session path's need for a real Origin-matched
        // "frontend" request — see EnsureFrontendRequestsAreStateful.
        $response = $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'CorrectHorse1!',
            'device_name' => 'test-device',
        ]);

        $response->assertOk();

        $log = AuditLog::where('user_id', $user->id)->where('action', AuditAction::LOGIN)->firstOrFail();
        $this->assertSame('Auth', $log->module);
    }

    public function test_a_failed_login_writes_a_login_failed_audit_log(): void
    {
        $user = $this->actingAsAdminWithPermissions([]);
        $user->forceFill(['password' => 'CorrectHorse1!'])->saveQuietly();

        $this->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'wrong-password',
        ])->assertUnprocessable();

        $log = AuditLog::where('user_id', $user->id)->where('action', AuditAction::LOGIN_FAILED)->firstOrFail();
        $this->assertSame('bad_password', $log->new_values['reason']);
    }

    public function test_changing_password_never_stores_the_password_in_any_audit_log(): void
    {
        $user = $this->actingAsAdminWithPermissions([]);
        $user->forceFill(['password' => 'CorrectHorse1!'])->save();

        $this->postJson('/api/v1/auth/change-password', [
            'current_password' => 'CorrectHorse1!',
            'password' => 'BrandNewSecret9!',
            'password_confirmation' => 'BrandNewSecret9!',
        ])->assertOk();

        $logs = AuditLog::where('user_id', $user->id)->get();

        $this->assertTrue($logs->contains(fn (AuditLog $l) => $l->action === AuditAction::PASSWORD_CHANGE));

        foreach ($logs as $log) {
            $payload = json_encode([$log->old_values, $log->new_values]);
            $this->assertStringNotContainsString('BrandNewSecret9', (string) $payload);
            $this->assertStringNotContainsString('CorrectHorse1', (string) $payload);

            foreach (['password', 'remember_token'] as $sensitive) {
                if (isset($log->old_values[$sensitive])) {
                    $this->assertSame('[redacted]', $log->old_values[$sensitive]);
                }
                if (isset($log->new_values[$sensitive])) {
                    $this->assertSame('[redacted]', $log->new_values[$sensitive]);
                }
            }
        }
    }

    public function test_a_user_without_the_permission_cannot_view_audit_logs(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/audit-logs')->assertForbidden();
    }

    public function test_audit_logs_are_paginated(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_VIEW, Permissions::STUDENTS_CREATE]);
        Student::factory()->count(3)->create();

        $response = $this->getJson('/api/v1/audit-logs?per_page=2');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('meta.pagination.type', 'length_aware');
    }

    public function test_filtering_by_module_and_action(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::AUDIT_LOGS_VIEW, Permissions::STUDENTS_CREATE, Permissions::POSITIONS_CREATE]);
        Student::factory()->create();
        Position::factory()->create();

        $response = $this->getJson('/api/v1/audit-logs?'.http_build_query(['filter' => ['module' => 'Students', 'action' => 'CREATE']]));

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.module', 'Students');
    }

    public function test_writing_an_audit_log_does_not_itself_create_another_audit_log(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $before = AuditLog::count();

        app(AuditLogger::class)->log(AuditAction::UPDATE, 'Students');
        app(AuditLogger::class)->log(AuditAction::UPDATE, 'Students');

        $this->assertSame($before + 2, AuditLog::count());
    }

    /**
     * Exactly two, not a runaway cascade: StudentController::store() also
     * auto-provisions a linked User account (see UserProvisioningService), so
     * one CREATE for the Student and one for that User is correct — proving
     * this stays at exactly 2 (not 3, 4, ...) is what actually demonstrates
     * an audit write can't recurse into further audit writes.
     */
    public function test_creating_a_student_writes_exactly_two_audit_logs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_CREATE]);

        $before = AuditLog::count();

        $this->postJson('/api/v1/students', [
            'first_name' => 'Dara',
            'last_name' => 'Sok',
            'phone' => '012345678',
        ])->assertCreated();

        $this->assertSame($before + 2, AuditLog::count());
        $this->assertSame(1, AuditLog::where('module', 'Students')->where('action', AuditAction::CREATE)->count());
        $this->assertSame(1, AuditLog::where('module', 'Users')->where('action', AuditAction::CREATE)->count());
    }
}
