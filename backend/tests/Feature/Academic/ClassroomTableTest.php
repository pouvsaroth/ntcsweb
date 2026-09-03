<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ClassroomTableTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_and_lists_tables_for_a_classroom(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_VIEW, Permissions::CLASSROOMS_CREATE]);
        $classroom = Classroom::factory()->create();

        $this->postJson('/api/v1/classroom-tables', ['classroom_id' => $classroom->id, 'name' => 'Table 1'])
            ->assertCreated()
            ->assertJsonPath('data.classroom.id', $classroom->id);

        $response = $this->getJson("/api/v1/classroom-tables?filter[classroom_id]={$classroom->id}");
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_the_same_table_name_can_repeat_across_different_classrooms_but_not_within_one(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE]);
        $roomA = Classroom::factory()->create();
        $roomB = Classroom::factory()->create();
        ClassroomTable::factory()->create(['classroom_id' => $roomA->id, 'name' => 'Table 1']);

        $this->postJson('/api/v1/classroom-tables', ['classroom_id' => $roomB->id, 'name' => 'Table 1'])->assertCreated();
        $this->postJson('/api/v1/classroom-tables', ['classroom_id' => $roomA->id, 'name' => 'Table 1'])->assertUnprocessable();
    }

    public function test_it_updates_and_deletes_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_UPDATE, Permissions::CLASSROOMS_DELETE]);
        $table = ClassroomTable::factory()->create(['name' => 'Table 1']);

        $this->putJson("/api/v1/classroom-tables/{$table->id}", ['name' => 'Table 1A'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Table 1A');

        $this->deleteJson("/api/v1/classroom-tables/{$table->id}")->assertNoContent();
        $this->assertSoftDeleted('classroom_tables', ['id' => $table->id]);
    }

    public function test_a_table_with_a_seated_student_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_DELETE]);
        $table = ClassroomTable::factory()->create();
        Enrollment::factory()->create(['table_id' => $table->id]);

        $response = $this->deleteJson("/api/v1/classroom-tables/{$table->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('classroom_tables', ['id' => $table->id, 'deleted_at' => null]);
    }
}
