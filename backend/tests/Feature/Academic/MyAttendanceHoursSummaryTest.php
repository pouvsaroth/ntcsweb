<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\MakeUpClassRequest;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Academic\AttendanceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * The student home screen's hour cards — see
 * MyAttendanceController::hoursSummary().
 */
class MyAttendanceHoursSummaryTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_it_totals_absent_make_up_and_remaining_hours_per_active_course(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);

        // 2-hour Monday sessions.
        $class = SchoolClass::factory()->create();
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '20:00:00')->create();
        $enrollment = Enrollment::factory()->forStudent($student)->forClass($class)->create();

        $monday = Carbon::parse('2026-01-05'); // Known Monday.
        $record = fn (int $week, string $status, array $extra = []) => AttendanceRecord::factory()->forEnrollment($enrollment)
            ->onDate($monday->copy()->addWeeks($week)->toDateString())->status($status)->create($extra);

        $record(0, AttendanceStatus::PRESENT);
        $record(1, AttendanceStatus::EXCUSED);                       // +2h
        $record(2, AttendanceStatus::ABSENT);                        // +2h
        $record(3, AttendanceStatus::ABSENT);                        // +2h
        $record(4, AttendanceStatus::ABSENT);                        // +2h
        $record(5, AttendanceStatus::LATE, ['late_minutes' => 30]);  // +0.5h

        // Approved: 2 days × 1.5h = 3h. Pending/rejected never count.
        MakeUpClassRequest::factory()->forStudent($student)->approved()->create([
            'enrollment_id' => $enrollment->id,
            'from_date' => '2026-02-02', 'to_date' => '2026-02-03',
            'from_time' => '08:00', 'to_time' => '09:30',
        ]);
        MakeUpClassRequest::factory()->forStudent($student)->create(['enrollment_id' => $enrollment->id]);
        MakeUpClassRequest::factory()->forStudent($student)->rejected()->create(['enrollment_id' => $enrollment->id]);

        // A dropped course is not one they're studying.
        Enrollment::factory()->forStudent($student)->dropped()->create();

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-attendance/hours-summary')->assertOk();

        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.enrollment_id', $enrollment->id);
        $response->assertJsonPath('data.0.absent_hours', 8.5);
        $response->assertJsonPath('data.0.make_up_hours', 3);
        $response->assertJsonPath('data.0.remaining_hours', 5.5);
    }

    public function test_remaining_hours_never_go_negative(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);
        $enrollment = Enrollment::factory()->forStudent($student)->create();

        MakeUpClassRequest::factory()->forStudent($student)->approved()->create(['enrollment_id' => $enrollment->id]);

        $this->actingAsTenantUser($user);
        $response = $this->getJson('/api/v1/my-attendance/hours-summary')->assertOk();

        $response->assertJsonPath('data.0.absent_hours', 0);
        $response->assertJsonPath('data.0.remaining_hours', 0);
    }
}
