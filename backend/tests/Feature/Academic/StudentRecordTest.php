<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AcademicYear;
use App\Models\AttendanceRecord;
use App\Models\Book;
use App\Models\Enrollment;
use App\Models\ExamApplication;
use App\Models\ExamScore;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Students > Student record, status, class assignment and search (docs/academics/student-record.md). */
class StudentRecordTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_students_are_found_by_class_and_academic_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $year = AcademicYear::factory()->create(['name' => '2026']);
        $class = SchoolClass::factory()->create(['academic_year_id' => $year->id]);
        $in = Student::factory()->create();
        Student::factory()->create();
        Enrollment::factory()->forClass($class)->forStudent($in)->create();

        $this->getJson("/api/v1/students?filter[class_id]={$class->id}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $in->id);
        $this->getJson("/api/v1/students?filter[academic_year_id]={$year->id}")->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/students')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_enrollments_by_academic_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_VIEW]);
        $year = AcademicYear::factory()->create();
        Enrollment::factory()->forClass(SchoolClass::factory()->create(['academic_year_id' => $year->id]))->create();
        Enrollment::factory()->create();

        $this->getJson("/api/v1/enrollments?filter[academic_year_id]={$year->id}")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_students_are_put_into_a_class_without_a_package(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ENROLLMENTS_CREATE]);
        $class = SchoolClass::factory()->create();
        [$a, $b] = Student::factory()->count(2)->create();

        $this->postJson("/api/v1/classes/{$class->id}/assign-students", ['student_ids' => [$a->id, $b->id]])
            ->assertOk()->assertJsonPath('data.added', 2);
        $this->postJson("/api/v1/classes/{$class->id}/assign-students", ['student_ids' => [$a->id]])
            ->assertOk()->assertJsonPath('data.added', 0)->assertJsonPath('data.skipped', 1);
        $this->assertSame(2, Enrollment::query()->where('class_id', $class->id)->whereNull('course_package_id')->count());
    }

    public function test_status_change_is_kept_in_history_and_ends_open_classes(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW, Permissions::STUDENTS_UPDATE]);
        $student = Student::factory()->create(['status' => Student::STATUS_ACTIVE]);
        $enrollment = Enrollment::factory()->forStudent($student)->create();

        $this->postJson("/api/v1/students/{$student->id}/status", ['status' => 'transferred', 'effective_date' => '2026-10-21'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reason', 'to_school']);
        $this->postJson("/api/v1/students/{$student->id}/status", [
            'status' => 'transferred', 'effective_date' => '2026-10-21', 'reason' => 'Family moved', 'to_school' => 'Hun Sen High School',
        ])->assertOk()->assertJsonPath('data.ended_classes', 1);

        $this->assertSame(Student::STATUS_TRANSFERRED, $student->fresh()->status);
        $this->assertSame(Enrollment::STATUS_STOPPED, $enrollment->fresh()->status);

        $record = $this->getJson("/api/v1/students/{$student->id}/record")->assertOk()->json('data');
        $this->assertSame('transferred', $record['status_history'][0]['to']);
        $this->assertSame('school', $record['transfers'][0]['kind']);
        $this->assertSame('Hun Sen High School', $record['transfers'][0]['to']);
    }

    public function test_the_record_shows_results_by_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $year = AcademicYear::factory()->create(['name' => '2026']);
        $class = SchoolClass::factory()->create(['name' => '7A', 'academic_year_id' => $year->id]);
        $student = Student::factory()->create();
        $enrollment = Enrollment::factory()->forClass($class)->forStudent($student)->create();
        foreach ([80, 60] as $score) {
            $app = ExamApplication::factory()->forStudent($student)->forEnrollment($enrollment)->create(['status' => ExamApplication::STATUS_APPROVED, 'book_id' => Book::factory()->create()->id]);
            ExamScore::query()->create(['exam_application_id' => $app->id, 'score' => $score]);
        }
        foreach ([AttendanceStatus::PRESENT, AttendanceStatus::ABSENT] as $i => $status) {
            AttendanceRecord::factory()->forEnrollment($enrollment)->onDate(now()->subDays($i + 1)->toDateString())->status($status)->create();
        }

        $row = $this->getJson("/api/v1/students/{$student->id}/record")->assertOk()->json('data.classes.0');
        $this->assertSame(['7A', '2026'], [$row['class'], $row['academic_year']]);
        $this->assertEquals(70, $row['average']);
        $this->assertEquals(50, $row['attendance_rate']);
        $this->assertSame(1, $row['absent_days']);
        $this->assertCount(2, $row['subjects']);
    }

    public function test_the_record_needs_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::CLASSES_VIEW]);
        $student = Student::factory()->create();

        $this->getJson("/api/v1/students/{$student->id}/record")->assertForbidden();
        $this->postJson("/api/v1/students/{$student->id}/status", ['status' => 'graduated', 'effective_date' => '2026-10-21'])->assertForbidden();
    }
}
