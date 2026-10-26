<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\ClassroomTable;
use App\Models\Enrollment;
use App\Models\EnrollmentStatusHistory;
use App\Models\EnrollmentTransferHistory;
use App\Models\ExamApplication;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Every place that changes an enrollment's status, class or table keeps a
 * history row — not only the Change status / Transfer screens.
 */
class EnrollmentHistoryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private function enrollment(array $attributes = []): Enrollment
    {
        return Enrollment::factory()
            ->forClass(SchoolClass::factory()->create())
            ->forStudent(Student::factory()->create())
            ->create(['status' => Enrollment::STATUS_ACTIVE, ...$attributes]);
    }

    private function statusRows(Enrollment $enrollment): array
    {
        return EnrollmentStatusHistory::query()->where('enrollment_id', $enrollment->id)->orderBy('id')
            ->get()->map(fn ($h) => [$h->from_status, $h->to_status])->all();
    }

    public function test_editing_the_status_keeps_history_and_an_unchanged_status_adds_none(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_UPDATE]);
        $enrollment = $this->enrollment();

        $this->patchJson("/api/v1/enrollments/{$enrollment->id}", ['status' => Enrollment::STATUS_COMPLETED])->assertOk();
        $this->patchJson("/api/v1/enrollments/{$enrollment->id}", ['enrolled_at' => '2026-09-01'])->assertOk();

        $this->assertSame([[Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED]], $this->statusRows($enrollment));
        $this->assertSame($this->admin->id, EnrollmentStatusHistory::query()->value('changed_by'));
    }

    public function test_cancelling_keeps_history_with_the_reason(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CANCEL]);
        $enrollment = $this->enrollment();

        $this->postJson("/api/v1/enrollments/{$enrollment->id}/cancel", ['reason' => 'Moved away'])->assertOk();

        $this->assertSame([[Enrollment::STATUS_ACTIVE, Enrollment::STATUS_DROPPED]], $this->statusRows($enrollment));
        $this->assertSame('Moved away', EnrollmentStatusHistory::query()->value('reason'));
    }

    public function test_marking_not_exam_keeps_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::EXAM_APPLICATIONS_UPDATE]);
        $enrollment = $this->enrollment();
        $application = ExamApplication::factory()->forStudent($enrollment->student)->forEnrollment($enrollment)->create([
            'status' => ExamApplication::STATUS_DRAFT,
        ]);

        $this->postJson("/api/v1/exam-applications/{$application->id}/not-exam")->assertOk();

        $this->assertSame([[Enrollment::STATUS_ACTIVE, Enrollment::STATUS_COMPLETED]], $this->statusRows($enrollment));
        $this->assertSame($this->admin->id, EnrollmentStatusHistory::query()->value('changed_by'));
    }

    public function test_returning_to_studying_with_the_table_taken_keeps_the_table_history(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CHANGE_STATUS]);
        $class = SchoolClass::factory()->create();
        $table = ClassroomTable::factory()->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent(Student::factory()->create())->create([
            'status' => Enrollment::STATUS_SUSPENDED, 'table_id' => $table->id,
        ]);
        Enrollment::factory()->forClass($class)->forStudent(Student::factory()->create())->create([
            'status' => Enrollment::TABLE_HOLDING_STATUS, 'table_id' => $table->id,
        ]);

        $this->postJson("/api/v1/enrollments/{$enrollment->id}/status", ['status' => Enrollment::TABLE_HOLDING_STATUS])->assertOk();

        $this->assertNull($enrollment->fresh()->table_id);
        $row = EnrollmentTransferHistory::query()->where('enrollment_id', $enrollment->id)->sole();
        $this->assertSame([$class->id, $class->id, $table->id, null], [$row->from_class_id, $row->to_class_id, $row->from_table_id, $row->to_table_id]);
    }

    public function test_the_list_shows_the_date_the_current_status_started(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW, Permissions::ENROLLMENTS_CHANGE_STATUS]);
        $never = $this->enrollment(['enrolled_at' => '2026-09-01']);
        $abandoned = $this->enrollment();
        $this->postJson("/api/v1/enrollments/{$abandoned->id}/status", [
            'status' => Enrollment::STATUS_ABANDONED, 'reason' => 'Stopped coming', 'effective_date' => '2026-10-15',
        ])->assertOk();

        $rows = collect($this->getJson('/api/v1/enrollments?per_page=50')->assertOk()->json('data'))->keyBy('id');
        $this->assertSame('2026-10-15', $rows[$abandoned->id]['status_date']);
        $this->assertNull($rows[$never->id]['status_date']);
        $this->assertSame('2026-09-01', $rows[$never->id]['enrolled_at']);
    }
}
