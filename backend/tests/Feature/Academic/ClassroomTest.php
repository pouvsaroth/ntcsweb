<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class ClassroomTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_creates_and_lists_classrooms(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_VIEW, Permissions::CLASSROOMS_CREATE]);

        $this->postJson('/api/v1/classrooms', ['name' => 'Room 101', 'capacity' => 25])->assertCreated();

        $response = $this->getJson('/api/v1/classrooms');
        $response->assertOk();
        $response->assertJsonCount(1, 'data');
    }

    public function test_room_name_must_be_unique_within_the_tenant(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_CREATE]);
        Classroom::factory()->create(['name' => 'Room 101']);

        $response = $this->postJson('/api/v1/classrooms', ['name' => 'Room 101']);

        $response->assertUnprocessable();
    }

    public function test_it_updates_and_deletes_a_classroom(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSROOMS_UPDATE, Permissions::CLASSROOMS_DELETE]);
        $classroom = Classroom::factory()->create();

        $this->putJson("/api/v1/classrooms/{$classroom->id}", ['capacity' => 50])
            ->assertOk()
            ->assertJsonPath('data.capacity', 50);

        $this->deleteJson("/api/v1/classrooms/{$classroom->id}")->assertNoContent();
        $this->assertSoftDeleted('classrooms', ['id' => $classroom->id]);
    }
}
