<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\Book;
use App\Models\ClassSchedule;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\TeacherDocument;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Teacher (docs/hrm/teachers.md). */
class TeacherManagementTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private Position $teacherPosition;

    private function setUpSchool(array $permissions = [Permissions::TEACHERS_VIEW, Permissions::TEACHERS_MANAGE]): void
    {
        $this->actingAsAdminWithPermissions($permissions);
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $this->teacherPosition = Position::factory()->create(['name' => 'Teacher', 'role_id' => $role->id]);
    }

    private function teacher(string $first): Staff
    {
        return Staff::factory()->create(['position_id' => $this->teacherPosition->id, 'first_name' => $first, 'status' => Staff::STATUS_ACTIVE]);
    }

    /** A class meeting on Monday 08:00–10:00. */
    private function mondayClass(string $name, ?Staff $teacher = null): SchoolClass
    {
        $class = SchoolClass::factory()->create(['name' => $name, 'status' => SchoolClass::STATUS_ACTIVE]);
        ClassSchedule::query()->create(['class_id' => $class->id, 'day_of_week' => 1, 'start_time' => '08:00', 'end_time' => '10:00']);
        if ($teacher) {
            $class->teachers()->attach($teacher->id);
        }

        return $class;
    }

    public function test_only_staff_in_a_teacher_position_are_teachers(): void
    {
        $this->setUpSchool();
        $this->teacher('Dara');
        Staff::factory()->create(['first_name' => 'Accountant']);

        $this->getJson('/api/v1/teachers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', fn ($n) => str_starts_with($n, 'Dara'));
    }

    public function test_qualifications_and_specialised_subjects(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $other = Staff::factory()->create();
        $math = Book::factory()->create(['title' => 'Mathematics']);
        $physics = Book::factory()->create(['title' => 'Physics']);

        $this->postJson('/api/v1/teachers/qualifications', ['staff_id' => $dara->id, 'type' => 'degree', 'level' => 'bachelor', 'title' => 'Bachelor of Mathematics', 'institution' => 'RUPP', 'year' => 2015])->assertCreated();
        $this->postJson('/api/v1/teachers/qualifications', ['staff_id' => $dara->id, 'type' => 'skill', 'title' => 'Microsoft Office'])->assertCreated();
        $this->postJson('/api/v1/teachers/qualifications', ['staff_id' => $other->id, 'type' => 'skill', 'title' => 'X'])
            ->assertUnprocessable()->assertJsonValidationErrors('staff_id');

        $this->putJson('/api/v1/teachers/subjects', ['staff_id' => $dara->id, 'book_ids' => [$math->id, $physics->id]])->assertOk();
        $this->putJson('/api/v1/teachers/subjects', ['staff_id' => $dara->id, 'book_ids' => [$math->id]])->assertOk();

        $data = $this->getJson("/api/v1/teachers/qualifications?staff_id={$dara->id}")->assertOk()->json('data');
        $this->assertSame(['Bachelor of Mathematics', 'Microsoft Office'], array_column($data['qualifications'], 'title'));
        $this->assertSame(['Mathematics'], array_column($data['subjects'], 'title'));
    }

    public function test_assignment_homeroom_and_teaching_load(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $class = $this->mondayClass('7A');
        $math = Book::factory()->create();

        $this->postJson('/api/v1/teachers/assignments', ['staff_id' => $dara->id, 'class_id' => $class->id, 'book_id' => $math->id, 'hours_per_week' => 4])->assertCreated();
        $this->postJson('/api/v1/teachers/assignments', ['staff_id' => $dara->id, 'class_id' => $class->id, 'book_id' => $math->id])
            ->assertUnprocessable()->assertJsonValidationErrors('class_id');
        // Assigned = teaches the class (attendance and scores of their classes).
        $this->assertTrue(DB::connection('tenant')->table('class_teachers')->where('class_id', $class->id)->where('staff_id', $dara->id)->exists());

        $this->putJson("/api/v1/teachers/homerooms/{$class->id}", ['staff_id' => $dara->id])->assertOk();
        $this->getJson('/api/v1/teachers/homerooms')->assertOk()->assertJsonPath('data.0.homeroom_staff_id', $dara->id);

        $load = collect($this->getJson('/api/v1/teachers/load')->assertOk()->json('data'))->keyBy('staff_id')[$dara->id];
        $this->assertEquals(2, $load['scheduled_hours']);
        $this->assertEquals(4, $load['assigned_hours']);
        $this->assertSame(['7A'], $load['homeroom']);
    }

    public function test_availability_shows_slots_leave_and_clashes(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $this->mondayClass('7A', $dara);

        $this->postJson('/api/v1/teachers/unavailability', ['staff_id' => $dara->id, 'kind' => 'weekly', 'day_of_week' => 1, 'start_time' => '09:00', 'end_time' => '11:00', 'reason' => 'University class'])->assertCreated();
        $this->postJson('/api/v1/teachers/unavailability', ['staff_id' => $dara->id, 'kind' => 'dates', 'from_date' => '2026-12-01'])
            ->assertCreated()->assertJsonPath('data.to_date', '2026-12-01');
        $this->postJson('/api/v1/teachers/unavailability', ['staff_id' => $dara->id, 'kind' => 'weekly'])
            ->assertUnprocessable()->assertJsonValidationErrors('day_of_week');
        LeaveRequest::query()->create(['staff_id' => $dara->id, 'from_date' => now()->addDays(3)->toDateString(), 'to_date' => now()->addDays(4)->toDateString(), 'days' => 2, 'reason' => 'Family', 'status' => LeaveRequest::STATUS_APPROVED]);

        $data = $this->getJson("/api/v1/teachers/availability?staff_id={$dara->id}")->assertOk()->json('data');
        $this->assertCount(2, $data['unavailable']);
        $this->assertCount(1, $data['leave']);
        $this->assertSame('7A', $data['clashes'][0]['class']);
    }

    public function test_substitute_candidates_leave_out_the_away_and_the_busy(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $free = $this->teacher('Free');
        $busy = $this->teacher('Busy');
        $away = $this->teacher('Away');
        $class = $this->mondayClass('7A', $dara);
        $this->mondayClass('8A', $busy);
        $monday = now()->next('Monday')->toDateString();
        LeaveRequest::query()->create(['staff_id' => $dara->id, 'from_date' => $monday, 'to_date' => $monday, 'days' => 1, 'reason' => 'Sick', 'status' => LeaveRequest::STATUS_APPROVED]);
        $this->postJson('/api/v1/teachers/unavailability', ['staff_id' => $away->id, 'kind' => 'weekly', 'day_of_week' => 1])->assertCreated();

        $c = $this->getJson("/api/v1/teachers/substitutions/candidates?class_id={$class->id}&date={$monday}")->assertOk()->json('data');
        $this->assertSame('08:00', $c['start_time']);
        $this->assertSame('leave', $c['class_teachers'][0]['away']);
        $this->assertSame([$free->id], array_column($c['available'], 'id'));
        $this->assertEqualsCanonicalizing([$busy->id, $away->id], array_column($c['not_available'], 'id'));

        $this->postJson('/api/v1/teachers/substitutions', ['class_id' => $class->id, 'absent_staff_id' => $dara->id, 'substitute_staff_id' => $free->id, 'date' => $monday, 'start_time' => '08:00', 'end_time' => '10:00'])->assertCreated();
        $this->postJson('/api/v1/teachers/substitutions', ['class_id' => $class->id, 'absent_staff_id' => $dara->id, 'substitute_staff_id' => $free->id, 'date' => $monday, 'start_time' => '08:00', 'end_time' => '10:00'])
            ->assertUnprocessable();
        $this->getJson("/api/v1/teachers/substitutions?from={$monday}&to={$monday}")->assertOk()->assertJsonPath('data.0.substitute', fn ($n) => str_starts_with($n, 'Free'));

        // Now covering 7A: not a candidate for another class at that time.
        $other = $this->mondayClass('9A');
        $again = $this->getJson("/api/v1/teachers/substitutions/candidates?class_id={$other->id}&date={$monday}")->json('data');
        $this->assertNotContains($free->id, array_column($again['available'], 'id'));
    }

    public function test_documents_are_private_and_need_permission(): void
    {
        Storage::fake('local');
        $this->setUpSchool();
        $dara = $this->teacher('Dara');

        $id = $this->post('/api/v1/teachers/documents', [
            'staff_id' => $dara->id, 'type' => 'contract', 'title' => 'Contract 2026',
            'file' => UploadedFile::fake()->create('contract.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $doc = TeacherDocument::query()->findOrFail($id);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->get("/api/v1/teachers/documents/{$id}/file")->assertOk();
        $this->getJson("/api/v1/teachers/documents?staff_id={$dara->id}")->assertOk()->assertJsonPath('data.0.title', 'Contract 2026');

        $this->actingAsAdminWithPermissions([Permissions::STAFF_VIEW]);
        $this->get("/api/v1/teachers/documents/{$id}/file")->assertForbidden();
        $this->getJson('/api/v1/teachers')->assertForbidden();
    }
}
