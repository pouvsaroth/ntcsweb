<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Book;
use App\Models\Classroom;
use App\Models\SchoolClass;
use App\Models\Teacher;
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
        $teacher = Teacher::factory()->create();
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
        $foreignTeacher = $this->createForOtherTenant(fn () => Teacher::factory()->forTenant(Tenant::factory()->create())->create());

        $response = $this->postJson('/api/v1/classes', [
            'name' => 'Suspicious Class',
            'teacher_id' => $foreignTeacher->id,
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
}
