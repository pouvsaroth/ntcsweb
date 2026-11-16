<?php

declare(strict_types=1);

namespace Tests\Feature\Staff;

use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\StaffCardDesign;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Staff cards: each school designs its own cards — size, front/back photos and placed fields. */
class StaffCardDesignTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function asDesigner(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::STAFF_CARDS_VIEW, Permissions::STAFF_CARDS_CREATE,
            Permissions::STAFF_CARDS_UPDATE, Permissions::STAFF_CARDS_DELETE,
        ]);
    }

    public function test_a_design_is_saved_with_photos_and_fields_then_updated_and_deleted(): void
    {
        Storage::fake('public');
        $this->asDesigner();

        $fields = json_encode([
            ['id' => 'a', 'type' => 'full_name', 'x' => 30.04, 'y' => 20, 'w' => 50, 'h' => 6, 'font_size' => 10, 'color' => '#112233', 'bold' => true, 'align' => 'center', 'font' => 'Battambang', 'junk' => 'x'],
            ['id' => 'b', 'type' => 'photo', 'x' => 4, 'y' => 12, 'w' => 22, 'h' => 28, 'shape' => 'rounded'],
            ['id' => 'c', 'type' => 'text', 'x' => 4, 'y' => 2, 'w' => 70, 'h' => 5, 'text' => 'កាតបុគ្គលិក'],
        ]);

        $id = $this->post('/api/v1/staff-card-designs', [
            'name' => 'Teacher card', 'width_mm' => 85.6, 'height_mm' => 54,
            'front_background' => UploadedFile::fake()->image('front.png', 856, 540),
            'front_fields' => $fields, 'back_fields' => '[]',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.width_mm', 85.6)
            ->assertJsonPath('data.front_fields.0.x', 30)
            ->assertJsonPath('data.front_fields.0.align', 'center')
            ->assertJsonPath('data.front_fields.0.font', 'Battambang')
            ->assertJsonPath('data.front_fields.1.shape', 'rounded')
            ->assertJsonPath('data.front_fields.2.text', 'កាតបុគ្គលិក')
            ->assertJsonPath('data.back_background_url', null)
            ->json('data.id');

        $design = StaffCardDesign::query()->findOrFail($id);
        $this->assertArrayNotHasKey('junk', $design->front_fields[0]);
        $front = $design->front_background_path;
        Storage::disk('public')->assertExists($front);

        // Replace the front photo, add a back one; only the name changes otherwise.
        $this->post("/api/v1/staff-card-designs/{$id}", [
            '_method' => 'PUT', 'name' => 'Teacher card 2026',
            'front_background' => UploadedFile::fake()->image('front2.png', 856, 540),
            'back_background' => UploadedFile::fake()->image('back.png', 856, 540),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Teacher card 2026')
            ->assertJsonCount(3, 'data.front_fields');
        Storage::disk('public')->assertMissing($front);

        // Remove the back photo.
        $this->post("/api/v1/staff-card-designs/{$id}", ['_method' => 'PUT', 'remove_back_background' => '1'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.back_background_url', null);

        $this->getJson('/api/v1/staff-card-designs')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/staff-card-designs/{$id}")->assertNoContent();
        $this->assertSame(0, StaffCardDesign::query()->count());
    }

    public function test_bad_fields_and_sizes_are_refused(): void
    {
        $this->asDesigner();

        $this->postJson('/api/v1/staff-card-designs', [
            'name' => 'Bad', 'width_mm' => 5, 'height_mm' => 54,
            'front_fields' => [['id' => 'a', 'type' => 'salary', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 5, 'color' => 'red', 'font' => 'Comic Sans']],
            'back_fields' => [],
        ])->assertUnprocessable()->assertJsonValidationErrors(['width_mm', 'front_fields.0.type', 'front_fields.0.color', 'front_fields.0.font']);
    }

    public function test_student_cards_are_their_own_list_with_student_fields_and_permissions(): void
    {
        $this->actingAsAdminWithPermissions([
            Permissions::STUDENT_CARDS_VIEW, Permissions::STUDENT_CARDS_CREATE, Permissions::STUDENT_CARDS_UPDATE, Permissions::STUDENT_CARDS_DELETE,
        ]);
        $staffDesign = StaffCardDesign::factory()->create();

        $fields = [['id' => 'a', 'type' => 'student_code', 'x' => 5, 'y' => 5, 'w' => 30, 'h' => 5], ['id' => 'b', 'type' => 'class_name', 'x' => 5, 'y' => 12, 'w' => 30, 'h' => 5]];
        $id = $this->postJson('/api/v1/staff-card-designs', ['card_for' => 'student', 'name' => 'Student card', 'width_mm' => 54, 'height_mm' => 85.6, 'front_fields' => $fields, 'back_fields' => []])
            ->assertCreated()->assertJsonPath('data.card_for', 'student')->json('data.id');

        // Each list shows only its own kind; staff cards need staff-card permissions.
        $this->getJson('/api/v1/staff-card-designs?card_for=student')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/staff-card-designs')->assertForbidden();
        $this->getJson("/api/v1/staff-card-designs/{$staffDesign->id}")->assertForbidden();

        // A staff-only field can't go on a student card.
        $this->postJson("/api/v1/staff-card-designs/{$id}", ['_method' => 'PUT', 'front_fields' => [['id' => 'c', 'type' => 'employee_code', 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 5]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['front_fields.0.type']);
    }

    public function test_students_for_cards_come_with_their_class_and_guardian_phone(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENT_CARDS_VIEW]);
        $class = SchoolClass::factory()->create(['name' => 'English 1A']);
        $other = SchoolClass::factory()->create(['name' => 'Math 2B']);
        $dara = Student::factory()->create(['student_code' => 'S001', 'english_name' => 'Dara']);
        $dara->guardians()->create(['guardian_name' => 'Sok Mom', 'guardian_type' => 'Mother', 'phone' => '012 111 222']);
        Enrollment::factory()->forStudent($dara)->forClass($class)->create();
        $sok = Student::factory()->create(['student_code' => 'S002']);
        Enrollment::factory()->forStudent($sok)->forClass($other)->create();

        $this->getJson('/api/v1/student-cards/students')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/student-cards/students?class_id={$class->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.student_code', 'S001')
            ->assertJsonPath('data.0.english_name', 'Dara')
            ->assertJsonPath('data.0.class_name', 'English 1A')
            ->assertJsonPath('data.0.guardian_phone', '012 111 222');
        $this->getJson('/api/v1/student-cards/students?search=s002')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.class_name', 'Math 2B');
    }

    public function test_staff_card_permissions_do_not_open_student_cards(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CARDS_VIEW]);
        $this->getJson('/api/v1/staff-card-designs?card_for=student')->assertForbidden();
        $this->getJson('/api/v1/student-cards/students')->assertForbidden();
    }

    public function test_viewing_only_cannot_add_or_delete(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STAFF_CARDS_VIEW]);
        $design = StaffCardDesign::factory()->create();

        $this->getJson('/api/v1/staff-card-designs')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/staff-card-designs', ['name' => 'X', 'width_mm' => 54, 'height_mm' => 85.6, 'front_fields' => [], 'back_fields' => []])->assertForbidden();
        $this->deleteJson("/api/v1/staff-card-designs/{$design->id}")->assertForbidden();
    }
}
