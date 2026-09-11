<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /**
     * A position that actually carries this tenant's Teacher role — not just
     * named "Teacher" — since that's what Position::teacherPositionIds()
     * (and therefore the teacher_id validation rule) checks for.
     */
    private function createTeacherPosition(): Position
    {
        $teacherRole = Role::factory()->forTenant($this->tenant)->system()->create([
            'slug' => Role::TEACHER,
            'name' => 'Teacher',
            'level' => Role::LEVELS[Role::TEACHER],
        ]);

        return Position::factory()->create(['name' => 'Teacher', 'role_id' => $teacherRole->id]);
    }

    public function test_it_creates_a_class_with_its_weekly_schedule(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $teacherPosition = $this->createTeacherPosition();
        $teacher = Staff::factory()->create(['position_id' => $teacherPosition->id]);
        $classroom = Classroom::factory()->create();

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Excel Basics — Evening Batch 1',
            'teacher_ids' => [$teacher->id],
            'classroom_id' => $classroom->id,
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '18:00', 'end_time' => '20:00'],
                ['day_of_week' => 3, 'start_time' => '18:00', 'end_time' => '20:00'],
                ['day_of_week' => 5, 'start_time' => '18:00', 'end_time' => '20:00'],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(3, 'data.schedules');
        $response->assertJsonPath('data.schedules.0.day_name', 'Monday');
        $response->assertJsonPath('data.teachers.0.id', $teacher->id);

        $this->assertDatabaseCount('class_schedules', 3, 'tenant');
    }

    public function test_a_class_can_have_multiple_teachers_and_assistant_teachers(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $teacherPosition = $this->createTeacherPosition();
        $mainTeacher = Staff::factory()->create(['position_id' => $teacherPosition->id]);
        $coTeacher = Staff::factory()->create(['position_id' => $teacherPosition->id]);
        $assistant = Staff::factory()->create(['position_id' => $teacherPosition->id]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Co-Taught Class',
            'teacher_ids' => [$mainTeacher->id, $coTeacher->id],
            'assistant_teacher_ids' => [$assistant->id],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data.teachers');
        $response->assertJsonCount(1, 'data.assistant_teachers');
        $this->assertEqualsCanonicalizing(
            [$mainTeacher->id, $coTeacher->id],
            collect($response->json('data.teachers'))->pluck('id')->all(),
        );
        $response->assertJsonPath('data.assistant_teachers.0.id', $assistant->id);
    }

    public function test_a_staff_member_cannot_be_both_teacher_and_assistant_on_the_same_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $teacherPosition = $this->createTeacherPosition();
        $staff = Staff::factory()->create(['position_id' => $teacherPosition->id]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Conflicted Class',
            'teacher_ids' => [$staff->id],
            'assistant_teacher_ids' => [$staff->id],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('assistant_teacher_ids');
    }

    public function test_the_has_active_enrollment_filter_excludes_classes_with_no_studying_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW]);

        $studyingClass = SchoolClass::factory()->create(['name' => 'Studying']);
        Enrollment::factory()->forClass($studyingClass)->create();

        $droppedOnlyClass = SchoolClass::factory()->create(['name' => 'Dropped Only']);
        Enrollment::factory()->forClass($droppedOnlyClass)->dropped()->create();

        $emptyClass = SchoolClass::factory()->create(['name' => 'Empty']);

        $response = $this->getJson('/api/v1/classes?filter[has_active_enrollment]=1');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Studying'));
        $this->assertFalse($names->contains('Dropped Only'));
        $this->assertFalse($names->contains('Empty'));
    }

    /**
     * The database CHECK constraint is the hard guarantee; the validation
     * rule is what turns violating it into a clean 422 instead of a 500.
     */
    public function test_a_schedule_slot_with_end_time_before_start_time_is_rejected(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Broken Schedule',
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '20:00', 'end_time' => '18:00'],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_a_staff_member_without_the_teacher_position_cannot_be_assigned_to_a_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $accountantPosition = Position::factory()->create(['name' => 'Accountant']);
        $accountant = Staff::factory()->create(['position_id' => $accountantPosition->id]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Suspicious Class',
            'teacher_ids' => [$accountant->id],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('teacher_ids.0');
    }

    /**
     * A school's position titles are free text — often translated, or a more
     * specific title like "Computer Teacher" — so eligibility is decided by
     * the position's Role, not by matching the literal string "Teacher".
     */
    public function test_a_staff_member_holding_a_differently_named_teacher_role_position_can_be_assigned_to_a_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $teacherRole = Role::factory()->forTenant($this->tenant)->system()->create([
            'slug' => Role::TEACHER,
            'name' => 'Teacher',
            'level' => Role::LEVELS[Role::TEACHER],
        ]);
        $computerTeacherPosition = Position::factory()->create(['name' => 'Computer Teacher', 'role_id' => $teacherRole->id]);
        $teacher = Staff::factory()->create(['position_id' => $computerTeacherPosition->id]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Computer Class',
            'teacher_ids' => [$teacher->id],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.teachers.0.id', $teacher->id);
    }

    public function test_updating_schedules_replaces_the_previous_set_entirely(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE, Permissions::CLASSES_UPDATE]);
        $class = SchoolClass::factory()->create();
        $class->schedules()->create(['day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']);

        $response = $this->putJson("/api/v1/classes/{$class->id}", [
            'schedules' => [
                ['day_of_week' => 6, 'start_time' => '09:00', 'end_time' => '11:00'],
            ],
        ]);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.schedules');
        $response->assertJsonPath('data.schedules.0.day_of_week', 6);
    }

    public function test_available_tables_reports_zero_total_for_a_class_with_no_classroom(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $class = SchoolClass::factory()->create();

        $response = $this->getJson("/api/v1/classes/{$class->id}/available-tables");

        $response->assertOk();
        $response->assertJsonPath('data.total_tables', 0);
        $response->assertJsonCount(0, 'data.available');
    }

    public function test_available_tables_reports_zero_total_for_a_classroom_with_no_tables(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $room = Classroom::factory()->create();
        $class = SchoolClass::factory()->inRoom($room)->create();

        $response = $this->getJson("/api/v1/classes/{$class->id}/available-tables");

        $response->assertOk();
        $response->assertJsonPath('data.total_tables', 0);
        $response->assertJsonCount(0, 'data.available');
    }

    public function test_available_tables_excludes_tables_taken_in_this_class_only(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $room = Classroom::factory()->create();
        $tableA = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table A']);
        $tableB = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table B']);
        $class = SchoolClass::factory()->inRoom($room)->create();
        $otherClass = SchoolClass::factory()->inRoom($room)->create();

        // Taken in $class — must not appear as available for $class.
        Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id]);
        // Taken in a DIFFERENT class sharing the same room — irrelevant to $class's own availability.
        Enrollment::factory()->forClass($otherClass)->create(['table_id' => $tableB->id]);

        $response = $this->getJson("/api/v1/classes/{$class->id}/available-tables");

        $response->assertOk();
        $response->assertJsonPath('data.total_tables', 2);
        $response->assertJsonCount(1, 'data.available');
        $response->assertJsonPath('data.available.0.id', $tableB->id);
    }
}
