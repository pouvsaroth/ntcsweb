<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\ParentStudent;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Teachers > Substitute "class not held" tells the class's students and parents — ClassNotHeldNotifier. */
class ClassNotHeldNoticeTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    private SchoolClass $class;

    private Staff $teacher;

    private User $studentUser;

    private User $parent;

    private User $otherStudentUser;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::TEACHERS_VIEW, Permissions::TEACHER_SUBSTITUTES_CREATE, Permissions::TEACHER_SUBSTITUTES_DELETE]);

        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $position = Position::factory()->create(['name' => 'Teacher', 'role_id' => $role->id]);
        $this->teacher = Staff::factory()->create(['position_id' => $position->id, 'status' => Staff::STATUS_ACTIVE]);
        $this->class = SchoolClass::factory()->withTeacher($this->teacher)->create(['name' => '7A', 'start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($this->class)->onDay(ClassSchedule::WEDNESDAY)->at('17:30:00', '18:30:00')->create();

        // A student with a login and a parent login, and one in another class.
        $this->studentUser = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $this->studentUser->id]);
        Enrollment::factory()->forClass($this->class)->forStudent($student)->create();
        $this->parent = User::factory()->forTenant($this->tenant)->create();
        ParentStudent::query()->create(['user_id' => $this->parent->id, 'student_id' => $student->id, 'relation' => 'mother']);
        $this->otherStudentUser = User::factory()->forTenant($this->tenant)->create();
        Enrollment::factory()->forClass(SchoolClass::factory()->create())->forStudent(Student::factory()->create(['user_id' => $this->otherStudentUser->id]))->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function notices(User $user, string $type = NotificationType::CLASS_NOT_HELD): Collection
    {
        return UserNotification::query()->where('recipient_id', $user->id)->where('type', $type)->get();
    }

    private function notHeld(array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/teachers/substitutions', $extra + ['class_id' => $this->class->id, 'absent_staff_id' => $this->teacher->id, 'date' => self::TODAY, 'start_time' => '17:30', 'end_time' => '18:30']);
    }

    public function test_students_and_parents_are_told_and_told_again_when_it_is_undone(): void
    {
        $id = $this->notHeld()->assertCreated()->assertJsonPath('data.notified', 2)->json('data.id');

        $notice = $this->notices($this->studentUser)->sole();
        $this->assertSame('7A', $notice->data['class_name']);
        $this->assertSame('11-11-2026', $notice->data['date']);
        $this->assertSame('17:30–18:30', $notice->data['time']);
        $this->assertSame('/admin/my-attendance', $notice->link);
        $this->assertSame('/admin/my-children', $this->notices($this->parent)->sole()->link);
        $this->assertCount(0, $this->notices($this->otherStudentUser));

        $this->deleteJson("/api/v1/teachers/substitutions/{$id}")->assertNoContent();
        $this->assertCount(1, $this->notices($this->studentUser, NotificationType::CLASS_BACK_ON));
        $this->assertCount(1, $this->notices($this->parent, NotificationType::CLASS_BACK_ON));
    }

    public function test_nobody_is_told_when_unticked_for_a_past_day_or_with_a_substitute(): void
    {
        $id = $this->notHeld(['notify_students' => false])->assertCreated()->assertJsonPath('data.notified', 0)->json('data.id');
        // Never told it was off: not told it is back on either.
        $this->deleteJson("/api/v1/teachers/substitutions/{$id}")->assertNoContent();

        $this->notHeld(['date' => '2026-11-04'])->assertCreated()->assertJsonPath('data.notified', 0);

        $free = Staff::factory()->create(['position_id' => $this->teacher->position_id, 'status' => Staff::STATUS_ACTIVE]);
        $this->notHeld(['substitute_staff_id' => $free->id])->assertCreated()->assertJsonPath('data.notified', 0);

        $this->assertSame(0, UserNotification::query()->whereIn('type', [NotificationType::CLASS_NOT_HELD, NotificationType::CLASS_BACK_ON])->count());
    }
}
