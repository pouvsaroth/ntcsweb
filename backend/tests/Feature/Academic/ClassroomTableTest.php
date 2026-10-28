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
        $this->assertSoftDeleted('classroom_tables', ['id' => $table->id], connection: 'tenant');
    }

    public function test_a_table_with_a_seated_student_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_DELETE]);
        $table = ClassroomTable::factory()->create();
        Enrollment::factory()->create(['table_id' => $table->id]);

        $response = $this->deleteJson("/api/v1/classroom-tables/{$table->id}");

        $response->assertStatus(422);
        $this->assertDatabaseHas('classroom_tables', ['id' => $table->id, 'deleted_at' => null], 'tenant');
    }

    public function test_bulk_creates_a_numbered_range_and_skips_names_already_there(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_VIEW, Permissions::CLASSROOMS_CREATE]);
        $room = Classroom::factory()->create();
        ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'NTS-T-03', 'sort_order' => 3]);
        $gone = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'NTS-T-05']);
        $gone->delete();

        $this->postJson('/api/v1/classroom-tables/bulk', [
            'classroom_id' => $room->id, 'prefix' => 'NTS-T-', 'count' => 40, 'start_number' => 1, 'allow_many_students' => true,
        ])->assertCreated()->assertJsonPath('data.created', 39)->assertJsonPath('data.skipped', 1);

        $tables = ClassroomTable::query()->where('classroom_id', $room->id)->orderBy('sort_order')->get();
        $this->assertCount(40, $tables);
        $this->assertSame('NTS-T-01', $tables->first()->name);
        $this->assertSame(1, $tables->first()->sort_order);
        $this->assertTrue($tables->first()->allow_many_students);
        $this->assertSame('NTS-T-40', $tables->last()->name);
        $this->assertSame(40, $tables->last()->sort_order);
        // The deleted one came back; the one already there was left alone.
        $this->assertNull($gone->fresh()->deleted_at);
        $this->assertFalse(ClassroomTable::query()->where('name', 'NTS-T-03')->first()->allow_many_students);
    }

    public function test_bulk_without_a_prefix_pads_to_the_widest_number(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE]);
        $room = Classroom::factory()->create();

        $this->postJson('/api/v1/classroom-tables/bulk', ['classroom_id' => $room->id, 'count' => 3, 'start_number' => 99])
            ->assertCreated()->assertJsonPath('data.created', 3);

        $this->assertSame(['099', '100', '101'], ClassroomTable::query()->where('classroom_id', $room->id)->orderBy('sort_order')->pluck('name')->all());
    }

    public function test_bulk_copies_another_rooms_tables(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE]);
        $from = Classroom::factory()->create();
        $to = Classroom::factory()->create();
        ClassroomTable::factory()->create(['classroom_id' => $from->id, 'name' => 'A1', 'sort_order' => 1]);
        ClassroomTable::factory()->create(['classroom_id' => $from->id, 'name' => 'Bench', 'sort_order' => 2, 'allow_many_students' => true, 'remark' => 'Back row']);

        $this->postJson('/api/v1/classroom-tables/bulk', ['classroom_id' => $to->id, 'copy_from_classroom_id' => $from->id])
            ->assertCreated()->assertJsonPath('data.created', 2);

        $bench = ClassroomTable::query()->where('classroom_id', $to->id)->where('name', 'Bench')->first();
        $this->assertSame(2, $bench->sort_order);
        $this->assertTrue($bench->allow_many_students);
        $this->assertSame('Back row', $bench->remark);
        $this->postJson('/api/v1/classroom-tables/bulk', ['classroom_id' => $to->id, 'copy_from_classroom_id' => $to->id])->assertUnprocessable();
    }

    public function test_bulk_needs_a_count_unless_copying(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE]);
        $room = Classroom::factory()->create();

        $this->postJson('/api/v1/classroom-tables/bulk', ['classroom_id' => $room->id])->assertUnprocessable()->assertJsonValidationErrors('count');
    }

    public function test_allow_many_students_can_be_switched_on_a_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_UPDATE]);
        $table = ClassroomTable::factory()->create();

        $this->putJson("/api/v1/classroom-tables/{$table->id}", ['allow_many_students' => true])
            ->assertOk()->assertJsonPath('data.allow_many_students', true);
    }

    public function test_a_table_keeps_a_remark(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE, Permissions::CLASSROOMS_UPDATE]);
        $room = Classroom::factory()->create();

        $id = $this->postJson('/api/v1/classroom-tables', ['classroom_id' => $room->id, 'name' => 'T1', 'remark' => 'Near the window'])
            ->assertCreated()->assertJsonPath('data.remark', 'Near the window')->json('data.id');

        $this->putJson("/api/v1/classroom-tables/{$id}", ['remark' => ''])->assertOk()->assertJsonPath('data.remark', null);

        $this->postJson('/api/v1/classroom-tables/bulk', ['classroom_id' => $room->id, 'prefix' => 'B', 'count' => 2, 'remark' => 'Lab bench'])->assertCreated();
        $this->assertSame(['Lab bench', 'Lab bench'], ClassroomTable::query()->where('classroom_id', $room->id)->where('name', 'like', 'B%')->pluck('remark')->all());
    }
}
