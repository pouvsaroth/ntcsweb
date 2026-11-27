<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\ClassMakeupSession;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\TeacherSubstitution;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\ClassAccess;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Teachers > Substitute: a whole-class make-up session for a not-held class. */
class ClassMakeupSessionTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    private Position $position;

    private SchoolClass $class;

    private Staff $dara;

    private User $studentUser;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW, Permissions::TIMETABLE_VIEW, Permissions::TEACHER_SUBSTITUTES_CREATE, Permissions::TEACHER_SUBSTITUTES_DELETE]);

        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $this->position = Position::factory()->create(['name' => 'Teacher', 'role_id' => $role->id]);
        $this->dara = $this->teacher('Dara');
        $this->class = SchoolClass::factory()->withTeacher($this->dara)->create(['name' => '7A', 'start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($this->class)->onDay(ClassSchedule::WEDNESDAY)->at('17:30:00', '18:30:00')->create();

        $this->studentUser = User::factory()->forTenant($this->tenant)->create();
        Enrollment::factory()->forClass($this->class)->forStudent(Student::factory()->create(['user_id' => $this->studentUser->id]))->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function teacher(string $first): Staff
    {
        return Staff::factory()->create(['position_id' => $this->position->id, 'first_name' => $first, 'last_name' => 'T', 'status' => Staff::STATUS_ACTIVE]);
    }

    private function notHeld(?int $substituteId = null): TeacherSubstitution
    {
        return TeacherSubstitution::query()->create(['class_id' => $this->class->id, 'absent_staff_id' => $this->dara->id, 'substitute_staff_id' => $substituteId, 'date' => self::TODAY, 'start_time' => '17:30', 'end_time' => '18:30']);
    }

    private function notices(string $type): int
    {
        return UserNotification::query()->where('recipient_id', $this->studentUser->id)->where('type', $type)->count();
    }

    public function test_a_make_up_session_is_set_shown_and_told_then_removed(): void
    {
        $row = $this->notHeld();

        // Saturday 08:00–09:00, taught by the teacher who was away (the default).
        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '09:00', 'note' => 'Room 2'])
            ->assertOk()
            ->assertJsonPath('data.notified', 1)
            ->assertJsonPath('data.makeup.date', '2026-11-14')
            ->assertJsonPath('data.makeup.staff_id', $this->dara->id)
            ->assertJsonPath('data.makeup.teacher', 'Dara T');
        $this->assertSame(1, $this->notices(NotificationType::CLASS_MAKEUP_SET));

        // Listed with its row, and on the teacher's week.
        $this->getJson('/api/v1/teachers/substitutions?from='.self::TODAY.'&to='.self::TODAY)->assertJsonPath('data.0.makeup.start_time', '08:00');
        $this->getJson("/api/v1/timetable/teacher?staff_id={$this->dara->id}&week=2026-11-14")->assertOk()
            ->assertJsonPath('data.makeups.0.date', '2026-11-14')->assertJsonPath('data.makeups.0.class', '7A');

        // Changing it keeps one session.
        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-14', 'start_time' => '09:00', 'end_time' => '10:00', 'notify_students' => false])->assertOk();
        $this->assertSame(1, ClassMakeupSession::query()->count());

        $this->deleteJson("/api/v1/teachers/substitutions/{$row->id}/makeup")->assertNoContent();
        $this->assertSame(0, ClassMakeupSession::query()->count());
        $this->assertSame(1, $this->notices(NotificationType::CLASS_MAKEUP_CANCELLED));
    }

    public function test_another_teacher_can_take_attendance_on_the_make_up_day(): void
    {
        $sokUser = User::factory()->forTenant($this->tenant)->create();
        $sok = Staff::factory()->withUser($sokUser)->create(['position_id' => $this->position->id, 'status' => Staff::STATUS_ACTIVE]);
        $row = $this->notHeld();

        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '09:00', 'staff_id' => $sok->id])->assertOk();

        // Sok's role sees only assigned classes; 7A is not one of them.
        $limited = Role::factory()->forTenant($this->tenant)->create(['name' => 'Class teacher', 'slug' => 'class-teacher', 'data_scope' => Role::SCOPE_ASSIGNED]);
        $sokUser->attachRoles($limited);
        app()->forgetScopedInstances();
        $access = app(ClassAccess::class);
        $this->assertTrue($access->canTakeAttendance($sokUser, $this->class, '2026-11-14'));
        $this->assertFalse($access->canTakeAttendance($sokUser, $this->class, '2026-11-15'));
    }

    public function test_refused_when_covered_too_early_or_the_teacher_is_not_free(): void
    {
        $covered = $this->notHeld($this->teacher('Free')->id);
        $this->putJson("/api/v1/teachers/substitutions/{$covered->id}/makeup", ['date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        $row = $this->notHeld();
        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-10', 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('date');

        // Dara teaches 8B on Saturday 08:00.
        $other = SchoolClass::factory()->withTeacher($this->dara)->create(['name' => '8B', 'status' => SchoolClass::STATUS_ACTIVE]);
        ClassSchedule::factory()->forClass($other)->onDay(ClassSchedule::SATURDAY)->at('07:30:00', '08:30:00')->create();
        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonPath('errors.staff_id.0', 'This teacher teaches 8B at that time.');

        // Dara is on leave on Sunday.
        LeaveRequest::query()->create(['staff_id' => $this->dara->id, 'from_date' => '2026-11-15', 'to_date' => '2026-11-15', 'days' => 1, 'reason' => 'Family', 'status' => LeaveRequest::STATUS_APPROVED]);
        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-15', 'start_time' => '08:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');
    }

    public function test_needs_the_substitute_permissions(): void
    {
        $row = $this->notHeld();
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW]);

        $this->putJson("/api/v1/teachers/substitutions/{$row->id}/makeup", ['date' => '2026-11-14', 'start_time' => '08:00', 'end_time' => '09:00'])->assertForbidden();
        $this->deleteJson("/api/v1/teachers/substitutions/{$row->id}/makeup")->assertForbidden();
    }
}
