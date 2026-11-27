<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\ClassSchedule;
use App\Models\Enrollment;
use App\Models\PayrollSetting;
use App\Models\SchoolClass;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\TeacherSubstitution;
use App\Models\Tenant;
use App\Services\Payroll\PayrollRules;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Payroll and Teachers > Class sessions: cover pay at the hourly rate ×
 * the cover rate, and missed sessions taken off at the hourly rate.
 */
class ClassSessionPayTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // 20:00 in Phnom Penh, Friday 27-11-2026.
        Carbon::setTestNow(Carbon::parse('2026-11-27 13:00:00', 'UTC'));
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_RUN, Permissions::TEACHERS_VIEW]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** 2,080,000៛ a month over 26 days of 8 hours: 10,000៛ an hour. */
    private function teacher(string $name): Staff
    {
        $staff = Staff::factory()->create(['first_name' => $name, 'last_name' => 'T', 'hire_date' => '2025-01-01']);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 2080000, 'currency' => Tenant::CURRENCY_KHR, 'effective_from' => '2025-01-01']);

        return $staff;
    }

    /**
     * A Monday 18:00–19:00 class taught by Dara. Monday 02-11 Sok covers it;
     * Monday 09-11 Dara does not check in and no attendance is saved: missed.
     */
    private function november(): array
    {
        $dara = $this->teacher('Dara');
        $sok = $this->teacher('Sok');
        $class = SchoolClass::factory()->withTeacher($dara)->create(['start_date' => '2026-10-01']);
        ClassSchedule::factory()->forClass($class)->onDay(ClassSchedule::MONDAY)->at('18:00:00', '19:00:00')->create();
        Enrollment::factory()->forClass($class)->create();
        TeacherSubstitution::query()->create(['class_id' => $class->id, 'absent_staff_id' => $dara->id, 'substitute_staff_id' => $sok->id, 'date' => '2026-11-02']);
        // Only the class-session lines: no day absence, late or early-leave deductions.
        PayrollSetting::current()->update(['deduct_absence' => false, 'deduct_unpaid_leave' => false, 'late_deduction_mode' => PayrollSetting::LATE_NONE, 'deduct_early_leave' => false]);

        return [$dara, $sok];
    }

    private function firstHalfSlips(): Collection
    {
        app(PayrollRules::class)->refresh();

        return collect($this->postJson('/api/v1/payroll-runs', ['month' => '2026-11', 'period' => 'first_half', 'pay_date' => '2026-11-15'])
            ->assertCreated()->json('data.payslips'))->keyBy('staff.id');
    }

    public function test_cover_is_paid_and_missed_sessions_are_taken_off(): void
    {
        [$dara, $sok] = $this->november();
        PayrollSetting::current()->update(['pay_class_cover' => true, 'class_cover_rate' => 1.5, 'deduct_missed_sessions' => true]);

        $slips = $this->firstHalfSlips();

        // Sok: 1 hour × 10,000៛ × 1.5.
        $this->assertSame(15000.0, (float) $slips[$sok->id]['cover_pay']);
        $this->assertSame(1040000.0 + 15000.0, (float) $slips[$sok->id]['gross_pay']);
        $this->assertSame(['type' => 'class_cover', 'code' => 'class_cover', 'name' => 'class_cover', 'amount' => 15000], collect($slips[$sok->id]['lines']['earnings'])->firstWhere('type', 'class_cover'));
        // Dara: covered on 02-11 (no pay, no deduction), missed 09-11: 1 hour × 10,000៛.
        $this->assertSame(0.0, (float) $slips[$dara->id]['cover_pay']);
        $this->assertSame(10000.0, (float) $slips[$dara->id]['attendance_deduction']);
        $this->assertSame(10000, collect($slips[$dara->id]['lines']['deductions'])->firstWhere('code', 'missed_sessions')['amount']);
        $this->assertSame(1030000.0, (float) $slips[$dara->id]['net_pay']);
    }

    public function test_nothing_changes_while_the_settings_are_off(): void
    {
        [$dara, $sok] = $this->november();

        $slips = $this->firstHalfSlips();

        $this->assertSame(0.0, (float) $slips[$sok->id]['cover_pay']);
        $this->assertSame(1040000.0, (float) $slips[$sok->id]['gross_pay']);
        $this->assertSame(0.0, (float) $slips[$dara->id]['attendance_deduction']);
    }

    public function test_class_sessions_does_not_list_days_still_to_come(): void
    {
        $this->november();

        $dates = collect($this->getJson('/api/v1/teachers/sessions?from=2026-11-23&to=2026-12-07')->assertOk()->json('data.rows'))->pluck('date')->unique()->values()->all();

        $this->assertSame(['2026-11-23'], $dates);
    }
}
