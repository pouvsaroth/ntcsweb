<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\Position;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\Student;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class SchoolClassTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_a_class_with_its_weekly_schedule_and_books(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $teacherPosition = Position::factory()->create(['name' => 'Teacher']);
        $teacher = Staff::factory()->create(['position_id' => $teacherPosition->id]);
        $classroom = Classroom::factory()->create();
        $book = Book::factory()->create();

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Excel Basics — Evening Batch 1',
            'teacher_id' => $teacher->id,
            'classroom_id' => $classroom->id,
            'schedules' => [
                ['day_of_week' => 1, 'start_time' => '18:00', 'end_time' => '20:00'],
                ['day_of_week' => 3, 'start_time' => '18:00', 'end_time' => '20:00'],
                ['day_of_week' => 5, 'start_time' => '18:00', 'end_time' => '20:00'],
            ],
            'book_ids' => [$book->id],
        ]);

        $response->assertCreated();
        $response->assertJsonCount(3, 'data.schedules');
        $response->assertJsonPath('data.schedules.0.day_name', 'Monday');
        $response->assertJsonPath('data.teacher.id', $teacher->id);
        $response->assertJsonCount(1, 'data.books');

        $this->assertDatabaseCount('class_schedules', 3);
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

    public function test_a_class_cannot_reference_a_teacher_from_another_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $foreignTeacher = $this->createForOtherTenant(function () {
            $tenant = Tenant::factory()->create();
            $position = Position::factory()->forTenant($tenant)->create(['name' => 'Teacher']);

            return Staff::factory()->forTenant($tenant)->create(['position_id' => $position->id]);
        });

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Suspicious Class',
            'teacher_id' => $foreignTeacher->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('teacher_id');
    }

    public function test_a_staff_member_without_the_teacher_position_cannot_be_assigned_to_a_class(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_CREATE]);
        $accountantPosition = Position::factory()->create(['name' => 'Accountant']);
        $accountant = Staff::factory()->create(['position_id' => $accountantPosition->id]);

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Suspicious Class',
            'teacher_id' => $accountant->id,
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors('teacher_id');
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

    public function test_a_class_from_another_tenant_cannot_be_fetched_directly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW]);
        $foreignClass = $this->createForOtherTenant(fn () => SchoolClass::factory()->forTenant(Tenant::factory()->create())->create());

        $this->getJson("/api/v1/classes/{$foreignClass->id}")->assertNotFound();
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
