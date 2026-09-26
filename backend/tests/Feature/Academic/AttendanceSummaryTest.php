<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\SchoolClass;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The Attendance Summary tab's data source — per-student present/permission/
 * absent day+hour totals over a date range, with LATE folded into "present"
 * and its own total-minutes-late figure. See AttendanceService::summarize().
 */
class AttendanceSummaryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** A Monday far enough in the future/past that "next monday" arithmetic never collides with `now()`. */
    private function monday(): Carbon
    {
        return Carbon::parse('2026-01-05'); // Known Monday.
    }

    public function test_summary_aggregates_present_permission_absent_days_and_hours_correctly(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);

        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '20:00:00')->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();

        $monday = $this->monday();

        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->toDateString())->status(AttendanceStatus::PRESENT)->create();
        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addWeek()->toDateString())->status(AttendanceStatus::PRESENT)->create();
        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addWeeks(2)->toDateString())->status(AttendanceStatus::LATE)
            ->create(['late_minutes' => 15]);
        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addWeeks(3)->toDateString())->status(AttendanceStatus::EXCUSED)->create();
        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addWeeks(4)->toDateString())->status(AttendanceStatus::ABSENT)->create();

        $response = $this->getJson("/api/v1/classes/{$class->id}/attendance-summary?date_from={$monday->toDateString()}&date_to=".$monday->copy()->addWeeks(4)->toDateString());

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $row = $response->json('data.0');

        $this->assertSame($enrollment->id, $row['enrollment_id']);
        // 2 PRESENT + 1 LATE all count as attended, each a 2-hour session.
        $this->assertSame(3, $row['present_days']);
        $this->assertEquals(6.0, $row['present_hours']);
        $this->assertSame(1, $row['permission_days']);
        $this->assertEquals(2.0, $row['permission_hours']);
        $this->assertSame(1, $row['absent_days']);
        $this->assertEquals(2.0, $row['absent_hours']);
        $this->assertSame(15, $row['late_minutes']);
    }

    public function test_summary_only_counts_records_inside_the_requested_date_range(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);

        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '20:00:00')->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();
        $monday = $this->monday();

        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->toDateString())->status(AttendanceStatus::PRESENT)->create();
        // Outside the range queried below.
        AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addMonths(2)->toDateString())->status(AttendanceStatus::ABSENT)->create();

        $response = $this->getJson("/api/v1/classes/{$class->id}/attendance-summary?date_from={$monday->toDateString()}&date_to=".$monday->copy()->addDays(6)->toDateString());

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame(1, $row['present_days']);
        $this->assertSame(0, $row['absent_days']);
    }

    public function test_summary_can_be_narrowed_to_one_student(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);

        $class = SchoolClass::factory()->create();
        $enrollmentA = Enrollment::factory()->forClass($class)->create();
        $enrollmentB = Enrollment::factory()->forClass($class)->create();

        $response = $this->getJson("/api/v1/classes/{$class->id}/attendance-summary?date_from=2026-01-01&date_to=2026-01-31&student_id={$enrollmentB->student_id}");

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame($enrollmentB->id, $response->json('data.0.enrollment_id'));
    }

    public function test_the_all_classes_summary_spans_every_class_with_each_rows_own_class_and_hours(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        $monday = $this->monday();

        $evening = SchoolClass::factory()->create(['name' => 'A Evening']);
        ClassSchedule::factory()->forClass($evening)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '20:00:00')->create();
        $morning = SchoolClass::factory()->create(['name' => 'B Morning']);
        ClassSchedule::factory()->forClass($morning)->onDay(ClassSchedule::MONDAY)->at('08:00:00', '11:00:00')->create();

        $inEvening = Enrollment::factory()->forClass($evening)->create();
        $inMorning = Enrollment::factory()->forClass($morning)->create();
        AttendanceRecord::factory()->forEnrollment($inEvening)->onDate($monday->toDateString())->status(AttendanceStatus::ABSENT)->create();
        AttendanceRecord::factory()->forEnrollment($inMorning)->onDate($monday->toDateString())->status(AttendanceStatus::ABSENT)->create();

        $response = $this->getJson("/api/v1/attendance-summary?date_from={$monday->toDateString()}&date_to={$monday->toDateString()}")->assertOk();

        $rows = collect($response->json('data'))->keyBy('enrollment_id');
        $this->assertCount(2, $rows);
        $this->assertSame('A Evening', $rows[$inEvening->id]['school_class']['name']);
        // Hours come from each enrollment's own class schedule.
        $this->assertEquals(2.0, $rows[$inEvening->id]['absent_hours']);
        $this->assertEquals(3.0, $rows[$inMorning->id]['absent_hours']);

        // class_id narrows it back to one class.
        $this->getJson("/api/v1/attendance-summary?class_id={$morning->id}&date_from={$monday->toDateString()}&date_to={$monday->toDateString()}")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.enrollment_id', $inMorning->id);
    }

    public function test_the_summary_can_be_filtered_by_enrollment_status(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_VIEW]);
        $class = SchoolClass::factory()->create();
        $studying = Enrollment::factory()->forClass($class)->create(['status' => Enrollment::STATUS_ACTIVE]);
        $completed = Enrollment::factory()->forClass($class)->create(['status' => Enrollment::STATUS_COMPLETED]);
        $stopped = Enrollment::factory()->forClass($class)->create(['status' => Enrollment::STATUS_STOPPED]);

        $ids = fn (string $query) => collect($this->getJson("/api/v1/attendance-summary?date_from=2026-01-01&date_to=2026-01-31{$query}")->assertOk()->json('data'))
            ->pluck('enrollment_id')->sort()->values()->all();

        // Default stays Studying only.
        $this->assertSame([$studying->id], $ids(''));
        $this->assertSame([$completed->id], $ids('&status=completed'));
        $this->assertSame(collect([$completed->id, $stopped->id])->sort()->values()->all(), $ids('&status=completed,stopped'));
        $this->assertSame(collect([$studying->id, $completed->id, $stopped->id])->sort()->values()->all(), $ids('&status=all'));

        $this->getJson('/api/v1/attendance-summary?date_from=2026-01-01&date_to=2026-01-31&status=bogus')
            ->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_summary_requires_the_attendance_view_permission(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $class = SchoolClass::factory()->create();

        $this->getJson("/api/v1/classes/{$class->id}/attendance-summary?date_from=2026-01-01&date_to=2026-01-31")
            ->assertForbidden();
    }

    public function test_late_minutes_is_recorded_only_for_late_entries_and_cleared_when_status_changes(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::ATTENDANCE_CREATE]);
        $class = SchoolClass::factory()->create();
        $enrollment = Enrollment::factory()->forClass($class)->create();
        $date = now()->toDateString();

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollment->id, 'status' => AttendanceStatus::LATE, 'late_minutes' => 20]],
        ])->assertOk();

        $this->assertSame(20, AttendanceRecord::where('enrollment_id', $enrollment->id)->first()->late_minutes);

        $this->postJson("/api/v1/classes/{$class->id}/attendance", [
            'date' => $date,
            'entries' => [['enrollment_id' => $enrollment->id, 'status' => AttendanceStatus::PRESENT]],
        ])->assertOk();

        $this->assertNull(AttendanceRecord::where('enrollment_id', $enrollment->id)->first()->late_minutes);
    }
}
