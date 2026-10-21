<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Classroom;
use App\Models\ClassSchedule;
use App\Models\GradeLevel;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherSubstitution;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Academic > Timetable (docs/academics/timetable.md). */
class TimetableTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Position $teacherPosition;

    private function school(array $permissions = [Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_MANAGE, Permissions::TIMETABLE_REQUEST]): void
    {
        $this->actingAsAdminWithPermissions($permissions);
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $this->teacherPosition = Position::factory()->create(['role_id' => $role->id]);
    }

    private function teacher(string $name): Staff
    {
        return Staff::factory()->create(['position_id' => $this->teacherPosition->id, 'first_name' => $name, 'status' => Staff::STATUS_ACTIVE]);
    }

    private function liveClass(string $name, ?Staff $teacher = null, ?Classroom $room = null): SchoolClass
    {
        $class = SchoolClass::factory()->create(['name' => $name, 'status' => SchoolClass::STATUS_ACTIVE, 'classroom_id' => $room?->id]);
        if ($teacher) {
            $class->teachers()->attach($teacher->id);
        }

        return $class;
    }

    public function test_a_slot_has_its_subject_teacher_and_room_and_the_class_form_keeps_them(): void
    {
        $this->school([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_MANAGE, Permissions::CLASSES_UPDATE]);
        $dara = $this->teacher('Dara');
        $room = Classroom::factory()->create(['name' => 'R1']);
        $class = $this->liveClass('7A', $dara);
        $math = Book::factory()->create(['title' => 'Math']);

        $id = $this->postJson('/api/v1/timetable/slots', ['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00', 'book_id' => $math->id, 'classroom_id' => $room->id])
            ->assertCreated()->assertJsonPath('data.slot.subject', 'Math')->assertJsonPath('data.slot.room', 'R1')
            ->assertJsonPath('data.slot.teachers.0.id', $dara->id)->json('data.slot.id');
        $this->postJson('/api/v1/timetable/slots', ['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '09:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('start_time');

        // The class form saves the same time (end changed): subject and room stay.
        $this->putJson("/api/v1/classes/{$class->id}", ['name' => '7A', 'schedules' => [['day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:30']]])->assertOk();
        $slot = ClassSchedule::query()->findOrFail($id);
        $this->assertSame([$math->id, $room->id, '08:30'], [$slot->book_id, $slot->classroom_id, substr((string) $slot->end_time, 0, 5)]);
    }

    public function test_conflicts_teacher_room_class_and_time_off(): void
    {
        $this->school();
        $dara = $this->teacher('Dara');
        $room = Classroom::factory()->create(['name' => 'R1']);
        $a = $this->liveClass('7A', $dara, $room);
        $b = $this->liveClass('8A', $dara, $room);
        ClassSchedule::query()->create(['class_id' => $a->id, 'day_of_week' => 2, 'start_time' => '08:00', 'end_time' => '09:00']);
        ClassSchedule::query()->create(['class_id' => $b->id, 'day_of_week' => 2, 'start_time' => '08:30', 'end_time' => '09:30']);
        ClassSchedule::query()->create(['class_id' => $b->id, 'day_of_week' => 3, 'start_time' => '08:00', 'end_time' => '09:00']);
        DB::connection('tenant')->table('teacher_unavailabilities')->insert(['staff_id' => $dara->id, 'kind' => 'weekly', 'day_of_week' => 3, 'created_at' => now(), 'updated_at' => now()]);

        $types = array_column($this->getJson('/api/v1/timetable/conflicts')->assertOk()->json('data'), 'type');
        $this->assertEqualsCanonicalizing(['teacher', 'room', 'unavailable'], $types);
    }

    public function test_teacher_week_with_substitutions_and_subject_hours(): void
    {
        $this->school();
        $dara = $this->teacher('Dara');
        $sok = $this->teacher('Sok');
        $grade = GradeLevel::query()->create(['code' => 'G7', 'name' => 'Grade 7', 'level_order' => 7]);
        $class = $this->liveClass('7A', $dara);
        $class->update(['grade_level_id' => $grade->id]);
        $math = Book::factory()->create(['title' => 'Math']);
        $grade->subjects()->attach($math->id, ['hours_per_week' => 4]);
        ClassSchedule::query()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '09:00', 'book_id' => $math->id]);
        $monday = now()->startOfWeek()->toDateString();
        TeacherSubstitution::query()->create(['class_id' => $class->id, 'absent_staff_id' => $dara->id, 'substitute_staff_id' => $sok->id, 'date' => $monday]);

        $week = $this->getJson("/api/v1/timetable/teacher?staff_id={$dara->id}&week={$monday}")->assertOk()->json('data');
        $this->assertCount(1, $week['slots']);
        $this->assertEquals(2, $week['hours_per_week']);
        $this->assertSame('away', $week['substitutions'][0]['role']);
        $this->assertSame('covering', $this->getJson("/api/v1/timetable/teacher?staff_id={$sok->id}&week={$monday}")->json('data.substitutions.0.role'));

        $row = $this->getJson("/api/v1/timetable/subjects?class_id={$class->id}")->assertOk()->json('data.subjects.0');
        $this->assertSame(['Math', 2.0, 4.0], [$row['subject'], (float) $row['timetable_hours'], (float) $row['curriculum_hours']]);
    }

    public function test_a_teacher_asks_for_a_change_and_an_admin_approves_it(): void
    {
        $this->school([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_REQUEST]);
        $me = $this->teacher('Me');
        $me->forceFill(['user_id' => $this->admin->id])->save();
        $other = $this->liveClass('9A', $this->teacher('Other'));
        $mine = $this->liveClass('7A', $me);
        $mySlot = ClassSchedule::query()->create(['class_id' => $mine->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00']);
        $notMine = ClassSchedule::query()->create(['class_id' => $other->id, 'day_of_week' => 1, 'start_time' => '07:00', 'end_time' => '08:00']);
        $ask = fn ($slot) => ['class_schedule_id' => $slot->id, 'new_day_of_week' => 4, 'new_start_time' => '13:00', 'new_end_time' => '14:00', 'reason' => 'Training on Monday'];

        $this->postJson('/api/v1/timetable/changes', $ask($notMine))->assertUnprocessable();
        $id = $this->postJson('/api/v1/timetable/changes', $ask($mySlot))->assertCreated()->json('data.id');
        $this->postJson('/api/v1/timetable/changes', $ask($mySlot))->assertUnprocessable();
        $this->postJson("/api/v1/timetable/changes/{$id}/approve")->assertForbidden();
        $this->getJson('/api/v1/timetable/changes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.mine', true);

        $this->actingAsAdminWithPermissions([Permissions::TIMETABLE_VIEW, Permissions::TIMETABLE_MANAGE]);
        $this->postJson("/api/v1/timetable/changes/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame([4, '13:00'], [(int) $mySlot->fresh()->day_of_week, substr((string) $mySlot->fresh()->start_time, 0, 5)]);
        $this->postJson("/api/v1/timetable/changes/{$id}/reject")->assertUnprocessable();
    }
}
