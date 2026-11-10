<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class AttendanceSheetTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array{0: SchoolClass, 1: array<string, Enrollment>} */
    private function classWithMarks(): array
    {
        $class = SchoolClass::factory()->create(['name' => 'Morning A']);
        $enrollments = [];
        foreach (['Anna', 'Bora', 'Chan', 'Dara'] as $name) {
            $student = Student::factory()->create(['first_name' => $name, 'last_name' => 'Test']);
            $enrollments[$name] = Enrollment::factory()->forClass($class)->create(['student_id' => $student->id]);
        }

        $mark = fn (string $name, string $date, string $status, ?int $late = null) => AttendanceRecord::factory()
            ->forEnrollment($enrollments[$name])->onDate($date)->status($status)->create(['late_minutes' => $late]);

        // 10-11: A absent, B present, C 30 min late, D permission.
        $mark('Anna', '2026-11-10', AttendanceStatus::ABSENT);
        $mark('Bora', '2026-11-10', AttendanceStatus::PRESENT);
        $mark('Chan', '2026-11-10', AttendanceStatus::LATE, 30);
        $mark('Dara', '2026-11-10', AttendanceStatus::EXCUSED);
        // 03-11: everyone present.
        foreach (array_keys($enrollments) as $name) {
            $mark($name, '2026-11-03', AttendanceStatus::PRESENT);
        }
        // Outside the range — not on the sheet.
        $mark('Anna', '2026-10-01', AttendanceStatus::ABSENT);

        return [$class, $enrollments];
    }

    public function test_the_sheet_lists_each_student_with_a_mark_per_class_day_and_totals(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        [$class] = $this->classWithMarks();

        $response = $this->getJson("/api/v1/classes/{$class->id}/attendance-sheet?date_from=2026-11-01&date_to=2026-11-30")->assertOk();

        $this->assertSame(['2026-11-03', '2026-11-10'], $response->json('data.dates'));
        $rows = collect($response->json('data.students'))->keyBy('student.name');
        $this->assertSame(['2026-11-03' => '✓', '2026-11-10' => 'A'], $rows['Anna Test']['marks']);
        $this->assertSame('✓', $rows['Bora Test']['marks']['2026-11-10']);
        $this->assertSame('30', $rows['Chan Test']['marks']['2026-11-10']);
        $this->assertSame('P', $rows['Dara Test']['marks']['2026-11-10']);
        $this->assertSame(['present' => 1, 'late' => 0, 'absent' => 1, 'permission' => 0, 'late_minutes' => 0], $rows['Anna Test']['totals']);
        $this->assertSame(['present' => 1, 'late' => 1, 'absent' => 0, 'permission' => 0, 'late_minutes' => 30], $rows['Chan Test']['totals']);
    }

    public function test_only_studying_students_are_listed_unless_another_status_is_picked(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        [$class, $enrollments] = $this->classWithMarks();
        $enrollments['Dara']->update(['status' => Enrollment::STATUS_STOPPED]);

        $names = fn (string $query) => collect($this->getJson("/api/v1/classes/{$class->id}/attendance-sheet?date_from=2026-11-01&date_to=2026-11-30{$query}")->assertOk()->json('data.students'))->pluck('student.name')->sort()->values()->all();

        $this->assertSame(['Anna Test', 'Bora Test', 'Chan Test'], $names(''));
        $this->assertSame(['Anna Test', 'Bora Test', 'Chan Test', 'Dara Test'], $names('&status=all'));
    }

    public function test_the_sheet_downloads_as_excel_and_pdf(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        [$class] = $this->classWithMarks();
        $query = 'date_from=2026-11-01&date_to=2026-11-30';

        $excel = $this->get("/api/v1/classes/{$class->id}/attendance-sheet/excel?{$query}")->assertOk();
        $excel->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attendance-Morning-A-2026-11-01-to-2026-11-30.xlsx', (string) $excel->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('PK', (string) $excel->getContent());

        $pdf = $this->get("/api/v1/classes/{$class->id}/attendance-sheet/pdf?{$query}")->assertOk();
        $pdf->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_dates_are_required_and_the_range_is_at_most_a_year(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        $class = SchoolClass::factory()->create();

        $this->getJson("/api/v1/classes/{$class->id}/attendance-sheet")->assertJsonValidationErrors(['date_from', 'date_to']);
        $this->getJson("/api/v1/classes/{$class->id}/attendance-sheet?date_from=2026-11-30&date_to=2026-11-01")->assertJsonValidationErrors(['date_to']);
        $this->getJson("/api/v1/classes/{$class->id}/attendance-sheet?date_from=2025-01-01&date_to=2026-11-01")->assertJsonValidationErrors(['date_to']);
    }

    public function test_the_sheet_requires_the_attendance_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        $class = SchoolClass::factory()->create();
        $query = 'date_from=2026-11-01&date_to=2026-11-30';

        $this->getJson("/api/v1/classes/{$class->id}/attendance-sheet?{$query}")->assertForbidden();
        $this->get("/api/v1/classes/{$class->id}/attendance-sheet/excel?{$query}")->assertForbidden();
        $this->get("/api/v1/classes/{$class->id}/attendance-sheet/pdf?{$query}")->assertForbidden();
    }
}
