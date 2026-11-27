<?php

declare(strict_types=1);

namespace Tests\Feature\Hrm;

use App\Models\AttendanceRecord;
use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\Position;
use App\Models\Role;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\TeacherSubstitution;
use App\Support\Academic\AttendanceStatus;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Teachers > Class sessions — ClassSessionReport. */
class ClassSessionReportTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const TODAY = '2026-11-11'; // a Wednesday

    private Position $teacherPosition;

    protected function setUp(): void
    {
        parent::setUp();
        // 10:00 in Phnom Penh.
        Carbon::setTestNow(Carbon::parse(self::TODAY.' 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function setUpSchool(array $permissions = [Permissions::TEACHERS_VIEW]): void
    {
        $this->actingAsAdminWithPermissions($permissions);
        $role = Role::factory()->forTenant($this->tenant)->system()->create(['slug' => Role::TEACHER, 'name' => 'Teacher', 'level' => Role::LEVELS[Role::TEACHER]]);
        $this->teacherPosition = Position::factory()->create(['name' => 'Teacher', 'role_id' => $role->id]);
    }

    private function teacher(string $first): Staff
    {
        return Staff::factory()->create(['position_id' => $this->teacherPosition->id, 'first_name' => $first, 'last_name' => 'T', 'status' => Staff::STATUS_ACTIVE]);
    }

    /** @param list<array{int, string, string}> $times */
    private function classWith(string $name, Staff $teacher, array $times, bool $students = true): array
    {
        $class = SchoolClass::factory()->create(['name' => $name, 'start_date' => '2026-10-01', 'status' => SchoolClass::STATUS_ACTIVE]);
        foreach ($times as [$day, $start, $end]) {
            ClassSchedule::factory()->forClass($class)->onDay($day)->at($start, $end)->create();
        }
        $class->teachers()->attach($teacher->id);

        return [$class, $students ? Enrollment::factory()->forClass($class)->create() : null];
    }

    private function attendance(SchoolClass $class, Enrollment $enrollment, string $date): void
    {
        AttendanceRecord::query()->create(['enrollment_id' => $enrollment->id, 'class_id' => $class->id, 'student_id' => $enrollment->student_id, 'date' => $date, 'status' => AttendanceStatus::PRESENT, 'recorded_at' => $date.' 08:30:00']);
    }

    private function checkIn(Staff $staff, string $date, string $time): void
    {
        StaffAttendance::query()->create(['staff_id' => $staff->id, 'date' => $date, 'check_in_at' => "{$date} {$time}:00", 'status' => StaffAttendance::STATUS_PRESENT]);
    }

    private function report(string $query = ''): array
    {
        return $this->getJson('/api/v1/teachers/sessions?from=2026-11-02&to=2026-11-15'.$query)->assertOk()->json('data');
    }

    public function test_each_session_gets_what_happened_to_it(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $sok = $this->teacher('Sok');
        $free = $this->teacher('Free');
        // Mon 08–10, Wed 08–09 (ended at 10:00 today), Wed 18–20 (not yet).
        [$a, $student] = $this->classWith('7A', $dara, [[ClassSchedule::MONDAY, '08:00:00', '10:00:00'], [ClassSchedule::WEDNESDAY, '08:00:00', '09:00:00'], [ClassSchedule::WEDNESDAY, '18:00:00', '20:00:00']]);
        [$b] = $this->classWith('8B', $sok, [[ClassSchedule::MONDAY, '13:00:00', '14:00:00']]);
        $this->classWith('9C', $sok, [[ClassSchedule::MONDAY, '15:00:00', '16:00:00']], students: false);

        // 7A Mon 02-11: taught, Dara checked in 15 minutes late.
        $this->attendance($a, $student, '2026-11-02');
        $this->checkIn($dara, '2026-11-02', '08:15');
        // 7A Wed 04-11: covered by Free (and Free saved attendance).
        TeacherSubstitution::query()->create(['class_id' => $a->id, 'absent_staff_id' => $dara->id, 'substitute_staff_id' => $free->id, 'date' => '2026-11-04']);
        $this->attendance($a, $student, '2026-11-04');
        // 7A Mon 09-11: not held.
        TeacherSubstitution::query()->create(['class_id' => $a->id, 'absent_staff_id' => $dara->id, 'date' => '2026-11-09', 'reason' => 'Sick']);
        // 7A Wed 11-11 08–09: Dara checked in, no attendance saved.
        $this->checkIn($dara, self::TODAY, '07:50');
        // 8B Mon 02-11: Sok did not check in; Mon 09-11: Sok on leave, nobody arranged.
        LeaveRequest::query()->create(['staff_id' => $sok->id, 'from_date' => '2026-11-09', 'to_date' => '2026-11-09', 'days' => 1, 'reason' => 'Family', 'status' => LeaveRequest::STATUS_APPROVED]);

        $data = $this->report();
        $status = collect($data['rows'])->map(fn ($r) => "{$r['date']} {$r['start_time']} {$r['class']} {$r['teacher']} {$r['status']} {$r['why']}")->all();

        $this->assertSame('2026-11-11', $data['to']);
        $this->assertEqualsCanonicalizing([
            '2026-11-02 08:00 7A Dara T taught ',
            '2026-11-02 13:00 8B Sok T missed no_check_in',
            '2026-11-04 08:00 7A Dara T covered ',
            '2026-11-04 08:00 7A Free T covering ',
            // A whole-day substitute covers the evening session too.
            '2026-11-04 18:00 7A Dara T covered ',
            '2026-11-04 18:00 7A Free T covering ',
            '2026-11-09 08:00 7A Dara T not_held ',
            '2026-11-09 13:00 8B Sok T missed leave',
            '2026-11-11 08:00 7A Dara T no_record ',
        ], $status);

        $summary = collect($data['summary'])->keyBy('teacher');
        $this->assertSame(['planned' => 5, 'taught' => 1, 'late' => 1, 'late_minutes' => 15, 'covered' => 2, 'not_held' => 1, 'missed' => 0, 'no_record' => 1, 'covering' => 0, 'taught_minutes' => 120, 'taught_percent' => 20],
            array_intersect_key($summary['Dara T'], array_flip(['planned', 'taught', 'late', 'late_minutes', 'covered', 'not_held', 'missed', 'no_record', 'covering', 'taught_minutes', 'taught_percent'])));
        $this->assertSame(2, $summary['Sok T']['missed']);
        $this->assertSame(2, $summary['Free T']['covering']);
        $this->assertSame(0, $summary['Free T']['planned']);
        $this->assertSame(180, $summary['Free T']['taught_minutes']);

        // Filters.
        $this->assertCount(2, $this->report("&staff_id={$sok->id}")['rows']);
        $this->assertCount(2, $this->report('&status=missed')['rows']);
        $this->assertCount(2, $this->report("&class_id={$b->id}")['rows']);
    }

    public function test_holidays_and_class_dates_are_skipped(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        [$a] = $this->classWith('7A', $dara, [[ClassSchedule::MONDAY, '08:00:00', '10:00:00']]);
        Holiday::query()->create(['name' => 'Water Festival', 'start_date' => '2026-11-02', 'end_date' => '2026-11-02']);
        $a->update(['end_date' => '2026-11-08']);

        $this->assertSame([], $this->report()['rows']);
    }

    public function test_needs_teachers_view(): void
    {
        $this->setUpSchool([]);
        $this->getJson('/api/v1/teachers/sessions?from=2026-11-02&to=2026-11-08')->assertForbidden();
    }

    public function test_range_is_at_most_93_days(): void
    {
        $this->setUpSchool();
        $this->getJson('/api/v1/teachers/sessions?from=2026-01-01&to=2026-11-08')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_excel_download(): void
    {
        $this->setUpSchool();
        $dara = $this->teacher('Dara');
        $this->classWith('7A', $dara, [[ClassSchedule::MONDAY, '08:00:00', '10:00:00']]);

        $this->get('/api/v1/teachers/sessions/excel?from=2026-11-02&to=2026-11-08')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }
}
