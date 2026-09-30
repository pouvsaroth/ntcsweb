<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Only a Studying student holds a table — see Enrollment::TABLE_HOLDING_STATUS.
 */
class TableAvailabilityTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array{0: SchoolClass, 1: ClassroomTable, 2: ClassroomTable} */
    private function classWithTwoTables(): array
    {
        $room = Classroom::factory()->create();
        $tableA = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table A']);
        $tableB = ClassroomTable::factory()->create(['classroom_id' => $room->id, 'name' => 'Table B']);

        return [SchoolClass::factory()->inRoom($room)->create(), $tableA, $tableB];
    }

    public function test_a_table_is_free_again_once_its_student_is_no_longer_studying(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        [$class, $tableA, $tableB] = $this->classWithTwoTables();

        Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => Enrollment::STATUS_STOPPED]);
        Enrollment::factory()->forClass($class)->create(['table_id' => $tableB->id, 'status' => Enrollment::STATUS_ACTIVE]);

        $response = $this->getJson("/api/v1/classes/{$class->id}/available-tables")->assertOk();

        $response->assertJsonCount(1, 'data.available');
        $response->assertJsonPath('data.available.0.id', $tableA->id);
    }

    public function test_every_non_studying_status_frees_the_table(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        [$class, $tableA] = $this->classWithTwoTables();

        foreach ([Enrollment::STATUS_SUSPENDED, Enrollment::STATUS_ABANDONED, Enrollment::STATUS_COMPLETED, Enrollment::STATUS_NOT_STARTED, Enrollment::STATUS_EXAM_READY] as $status) {
            Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => $status]);
        }

        // All five sit at Table A — none holds it, so it's still available,
        // and the DB index accepts a Studying student there too.
        $this->getJson("/api/v1/classes/{$class->id}/available-tables")
            ->assertOk()
            ->assertJsonCount(2, 'data.available');

        Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => Enrollment::STATUS_ACTIVE]);
        $this->assertSame(6, Enrollment::query()->where('table_id', $tableA->id)->count());
    }

    public function test_a_returning_student_keeps_their_table_when_it_is_still_free(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_STATUS]);
        [$class, $tableA] = $this->classWithTwoTables();
        $enrollment = Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => Enrollment::STATUS_SUSPENDED]);

        $this->postJson("/api/v1/enrollments/{$enrollment->id}/status", ['status' => Enrollment::STATUS_ACTIVE])->assertOk();

        $this->assertSame($tableA->id, $enrollment->fresh()->table_id);
    }

    public function test_a_returning_student_whose_table_was_given_away_has_it_cleared(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_STATUS]);
        [$class, $tableA] = $this->classWithTwoTables();
        $returning = Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => Enrollment::STATUS_SUSPENDED]);
        Enrollment::factory()->forClass($class)->create(['table_id' => $tableA->id, 'status' => Enrollment::STATUS_ACTIVE]);

        $this->postJson("/api/v1/enrollments/{$returning->id}/status", ['status' => Enrollment::STATUS_ACTIVE])
            ->assertOk()
            ->assertJsonPath('data.table_id', null);

        $this->assertSame(Enrollment::STATUS_ACTIVE, $returning->fresh()->status);
        $this->assertNull($returning->fresh()->table_id);
    }
}
