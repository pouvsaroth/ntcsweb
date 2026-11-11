<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Classroom;
use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\TestCase;

/**
 * Start study date: later than today = Not started until that day, then
 * Studying (enrollments:start-due). Today or earlier = Studying at once.
 */
class EnrollmentStartStudyTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, RefreshDatabase;

    private function today(): string
    {
        return now('Asia/Phnom_Penh')->toDateString();
    }

    /** @param  array<string, mixed>  $extra */
    private function enroll(Student $student, SchoolClass $class, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/enrollments/package', [
            'student_id' => $student->id, 'class_id' => $class->id, 'course_package_id' => $this->msWordPackage->id,
            'fee_type' => 'term', 'enrolled_at' => $this->today(), ...$extra,
        ]);
    }

    public function test_a_later_start_date_is_not_started_and_no_date_starts_on_the_enrollment_date(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->create();
        $later = now('Asia/Phnom_Penh')->addMonth()->toDateString();

        $this->enroll(Student::factory()->create(), $class, ['start_study_on' => $later])->assertCreated()
            ->assertJsonPath('data.status', Enrollment::STATUS_NOT_STARTED)
            ->assertJsonPath('data.start_study_on', $later);

        $this->enroll(Student::factory()->create(), $class)->assertCreated()
            ->assertJsonPath('data.status', Enrollment::STATUS_ACTIVE)
            ->assertJsonPath('data.start_study_on', $this->today());

        $this->enroll(Student::factory()->create(), $class, ['start_study_on' => now('Asia/Phnom_Penh')->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('start_study_on');

        // The list sorts by it.
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $this->getJson('/api/v1/enrollments?sort=-start_study_on')->assertOk()->assertJsonPath('data.0.start_study_on', $later);
    }

    public function test_on_the_start_date_it_turns_studying_and_a_seat_taken_meanwhile_is_cleared(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $this->setUpAcademicCatalog();
        $room = Classroom::factory()->create();
        $table = ClassroomTable::factory()->create(['classroom_id' => $room->id]);
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->inRoom($room)->create();
        $start = now('Asia/Phnom_Penh')->addDays(10)->toDateString();

        $later = $this->enroll(Student::factory()->create(), $class, ['start_study_on' => $start, 'table_id' => $table->id])
            ->assertCreated()->json('data.id');
        // The seat is free until they start: someone studying now takes it.
        $this->enroll(Student::factory()->create(), $class, ['table_id' => $table->id])->assertCreated();

        $this->artisan('enrollments:start-due')->assertSuccessful();
        $this->assertSame(Enrollment::STATUS_NOT_STARTED, Enrollment::findOrFail($later)->status);

        $this->travelTo(now()->addDays(10)->setTime(12, 0));
        $this->artisan('enrollments:start-due')->assertSuccessful();

        $enrollment = Enrollment::findOrFail($later);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertNull($enrollment->table_id);
        $history = EnrollmentStatusHistory::query()->where('enrollment_id', $later)->sole();
        $this->assertSame(Enrollment::STATUS_NOT_STARTED, $history->from_status);
        $this->assertSame(Enrollment::STATUS_ACTIVE, $history->to_status);
        $this->assertSame('Start study date reached', $history->reason);
    }

    public function test_editing_the_start_date_moves_the_status_with_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE, Permissions::ENROLLMENTS_UPDATE]);
        $this->setUpAcademicCatalog();
        $class = SchoolClass::factory()->forProgram($this->computerProgram)->create();
        $id = $this->enroll(Student::factory()->create(), $class)->assertCreated()->json('data.id');

        $this->putJson("/api/v1/enrollments/{$id}", ['start_study_on' => now('Asia/Phnom_Penh')->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', Enrollment::STATUS_NOT_STARTED);
        $this->putJson("/api/v1/enrollments/{$id}", ['start_study_on' => $this->today()])
            ->assertOk()->assertJsonPath('data.status', Enrollment::STATUS_ACTIVE);
        $this->putJson("/api/v1/enrollments/{$id}", ['start_study_on' => now('Asia/Phnom_Penh')->subYear()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('start_study_on');

        // A finished enrollment keeps its status.
        Enrollment::query()->whereKey($id)->update(['status' => Enrollment::STATUS_COMPLETED]);
        $this->putJson("/api/v1/enrollments/{$id}", ['start_study_on' => now('Asia/Phnom_Penh')->addWeek()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', Enrollment::STATUS_COMPLETED);
    }
}
