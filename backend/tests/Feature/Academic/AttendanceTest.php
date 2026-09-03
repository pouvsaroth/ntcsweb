<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AuditLog;
use App\Models\Enrollment;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use App\Support\Audit\AuditAction;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function classWithStudents(int $count = 3): array
    {
        $class = SchoolClass::factory()->forTenant($this->tenant)->create();
        $enrollments = Enrollment::factory()
            ->forTenant($this->tenant)
            ->forClass($class)
            ->count($count)
            ->create();

        return [$class, $enrollments];
    }

    public function test_the_roster_lists_every_enrolled_student_with_no_status_before_anything_is_recorded(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(3);

        $response = $this->getJson("/api/v1/classes/{$class->id}/attendance?date=".now()->toDateString());

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $this->assertNull($response->json('data.0.status'));
    }

    public function test_recording_attendance_saves_one_record_per_student_and_one_audit_entry_for_the_whole_batch(): void
    {
        $admin = $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE, Permissions::ATTENDANCE_VIEW]);
        [$class, $enrollments] = $this->classWithStudents(3);
        $date = now()->toDateString();

        $before = AuditLog::count();

        $response = $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [
                ['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT],
                ['enrollment_id' => $enrollments[1]->id, 'status' => AttendanceStatus::ABSENT, 'remarks' => 'Sick'],
                ['enrollment_id' => $enrollments[2]->id, 'status' => AttendanceStatus::LATE],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $this->assertSame(3, \App\Models\AttendanceRecord::where('class_id', $class->id)->where('date', $date)->count());

        // Exactly one new audit row for the whole batch, not one per student.
        $this->assertSame($before + 1, AuditLog::count());
        $log = AuditLog::where('action', AuditAction::ATTENDANCE_RECORDED)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertStringContainsString('1 absent', $log->description);
    }

    public function test_recording_attendance_twice_for_the_same_date_updates_rather_than_duplicates(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(1);
        $date = now()->toDateString();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::ABSENT]],
        ])->assertOk();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertOk();

        $this->assertSame(1, \App\Models\AttendanceRecord::where('enrollment_id', $enrollments[0]->id)->count());
        $this->assertSame(AttendanceStatus::PRESENT, \App\Models\AttendanceRecord::where('enrollment_id', $enrollments[0]->id)->first()->status);
    }

    public function test_a_student_not_enrolled_in_the_class_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(1);
        $otherEnrollment = Enrollment::factory()->forTenant($this->tenant)->create();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => now()->toDateString(),
            'entries' => [['enrollment_id' => $otherEnrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertUnprocessable();
    }

    public function test_a_future_date_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(1);

        // +2 days, not +1: leaves a full day of margin against a CI runner's
        // clock ticking over a day boundary between building this payload
        // and the backend evaluating `before_or_equal:today`, which would
        // otherwise make "tomorrow" and "today" collide once in a while.
        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => now()->addDays(2)->toDateString(),
            'entries' => [['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertUnprocessable();
    }

    public function test_recording_attendance_requires_the_attendance_create_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        [$class, $enrollments] = $this->classWithStudents(1);

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => now()->toDateString(),
            'entries' => [['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertForbidden();
    }

    public function test_a_teacher_can_only_take_attendance_for_their_own_class(): void
    {
        $teacherUser = $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE, Permissions::ATTENDANCE_VIEW]);
        $teacherPosition = Position::factory()->forTenant($this->tenant)->create(['name' => 'Teacher']);
        $teacher = Staff::factory()->forTenant($this->tenant)->withUser($teacherUser)->create(['position_id' => $teacherPosition->id]);

        $ownClass = SchoolClass::factory()->forTenant($this->tenant)->withTeacher($teacher)->create();
        $ownEnrollment = Enrollment::factory()->forTenant($this->tenant)->forClass($ownClass)->create();

        $otherClass = SchoolClass::factory()->forTenant($this->tenant)->create();
        $otherEnrollment = Enrollment::factory()->forTenant($this->tenant)->forClass($otherClass)->create();

        $this->postJson("/api/v1/classes/{$ownClass->id}/attendance", [
            'date' => now()->toDateString(),
            'entries' => [['enrollment_id' => $ownEnrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertOk();

        $this->postJson("/api/v1/classes/{$otherClass->id}/attendance", [
            'date' => now()->toDateString(),
            'entries' => [['enrollment_id' => $otherEnrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertForbidden();
    }

    public function test_a_school_admin_without_a_linked_staff_record_can_take_attendance_for_any_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(1);

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => now()->toDateString(),
            'entries' => [['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertOk();
    }

    public function test_a_student_sees_only_their_own_attendance_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        [$class, $enrollments] = $this->classWithStudents(2);
        $date = now()->toDateString();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [
                ['enrollment_id' => $enrollments[0]->id, 'status' => AttendanceStatus::PRESENT],
                ['enrollment_id' => $enrollments[1]->id, 'status' => AttendanceStatus::ABSENT],
            ],
        ])->assertOk();

        $ownerStudent = Student::findOrFail($enrollments[0]->student_id);
        $ownerUser = User::factory()->forTenant($this->tenant)->create();
        $ownerStudent->forceFill(['user_id' => $ownerUser->id])->save();

        $this->actingAsTenantUser($ownerUser);
        $response = $this->getJson('/api/v1/my-attendance')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.status', AttendanceStatus::PRESENT);
    }

    public function test_viewing_all_attendance_requires_the_attendance_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);

        $this->getJson('/api/v1/attendance')->assertForbidden();
    }
}
