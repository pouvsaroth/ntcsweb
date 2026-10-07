<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\CurrencyRate;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\OvertimeRequest;
use App\Models\PayrollSetting;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\StaffAttendance;
use App\Models\StaffLoan;
use App\Models\StaffPayrollProfile;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Models\WorkSchedule;
use App\Services\Payroll\PayrollRules;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Payroll's rules (stage 2) — overtime, attendance deduction, Tax on
 * Salary, NSSF and loans/advances.
 */
class PayrollRulesTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PAYROLL_VIEW, Permissions::PAYROLL_MANAGE];

    /** $520 a month over 26 days of 8 hours: $20 a day, $2.50 an hour. */
    private function staffOn520(array $attributes = []): Staff
    {
        $staff = Staff::factory()->create(['hire_date' => now()->subYear()->toDateString(), ...$attributes]);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 520, 'currency' => Tenant::CURRENCY_USD, 'effective_from' => now()->subYear()->toDateString()]);

        return $staff;
    }

    public function test_the_rules_start_with_cambodias_defaults_and_need_manage_to_change(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW]);

        $response = $this->getJson('/api/v1/payroll-rules')->assertOk();
        $response->assertJsonPath('data.settings.working_days_per_month', 26);
        $response->assertJsonPath('data.settings.overtime_normal_rate', 1.5);
        $response->assertJsonPath('data.settings.tax_child_allowance', 150000);
        $response->assertJsonCount(5, 'data.tax_brackets');
        $response->assertJsonCount(3, 'data.social_security_schemes');

        $this->putJson('/api/v1/payroll-rules/settings', ['hours_per_day' => 7])->assertForbidden();

        $this->actingAsAdminWithPermissions(self::ALL);
        $this->putJson('/api/v1/payroll-rules/settings', ['hours_per_day' => 7, 'late_deduction_mode' => 'sometimes'])
            ->assertUnprocessable()->assertJsonValidationErrors(['late_deduction_mode']);
        $this->putJson('/api/v1/payroll-rules/settings', ['hours_per_day' => 7])->assertOk()->assertJsonPath('data.settings.hours_per_day', 7);
    }

    public function test_tax_on_salary_is_progressive_less_dependant_allowances_and_flat_for_non_residents(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $rules = app(PayrollRules::class);
        $resident = new StaffPayrollProfile;

        $this->assertSame(0.0, $rules->tax(1500000, $resident)['tax']);
        $this->assertSame(25000.0, $rules->tax(2000000, $resident)['tax']);
        // 500,000 at 5% + 6,500,000 at 10% + 1,500,000 at 15%.
        $this->assertSame(900000.0, $rules->tax(10000000, $resident)['tax']);

        // A spouse and a child take 300,000 off first.
        $family = new StaffPayrollProfile(['spouse_dependent' => true, 'child_dependents' => 1]);
        $this->assertSame(['taxable' => 2000000.0, 'allowances' => 300000.0, 'base' => 1700000.0, 'tax' => 10000.0], $rules->tax(2000000, $family));

        $nonResident = new StaffPayrollProfile(['tax_resident' => false, 'spouse_dependent' => true]);
        $this->assertSame(400000.0, $rules->tax(2000000, $nonResident)['tax']);
    }

    public function test_nssf_is_worked_out_on_the_wage_between_its_floor_and_ceiling(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $rules = app(PayrollRules::class);

        $mid = $rules->socialSecurity(1000000, true);
        $this->assertSame(20000.0, $mid['employee']); // pension 2%
        $this->assertSame(54000.0, $mid['employer']); // 0.8% + 2.6% + 2%
        $this->assertSame(20000.0, $mid['reduces_taxable']);

        $this->assertSame(24000.0, $rules->socialSecurity(5000000, true)['employee']); // ceiling 1,200,000
        $this->assertSame(8000.0, $rules->socialSecurity(300000, true)['employee']); // floor 400,000
        $this->assertSame(0.0, $rules->socialSecurity(1000000, false)['employee']);
    }

    public function test_the_calculator_converts_a_usd_salary_at_the_schools_rate(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        CurrencyRate::query()->create(['effective_date' => now()->subMonth()->toDateString(), 'khr_per_usd' => 4000]);

        // $500 = 2,000,000៛; pension 24,000 comes off: tax on 1,976,000 = 23,800៛ = $5.95.
        $response = $this->getJson('/api/v1/payroll-rules/preview?amount=500&currency=USD')->assertOk();

        $response->assertJsonPath('data.wage_khr', 2000000);
        $response->assertJsonPath('data.tax.tax', 23800);
        $response->assertJsonPath('data.in_currency.tax', 5.95);
        $response->assertJsonPath('data.in_currency.social_security_employee', 6);
        $response->assertJsonPath('data.in_currency.net', 488.05);
    }

    public function test_tax_brackets_are_replaced_as_a_whole_and_must_join_up(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $this->putJson('/api/v1/payroll-rules/tax-brackets', ['brackets' => [
            ['min_amount' => 0, 'max_amount' => 1000000, 'rate' => 0],
            ['min_amount' => 1200000, 'max_amount' => null, 'rate' => 10],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['brackets.0.max_amount']);

        $this->putJson('/api/v1/payroll-rules/tax-brackets', ['brackets' => [
            ['min_amount' => 0, 'max_amount' => null, 'rate' => 0],
            ['min_amount' => 1000000, 'max_amount' => null, 'rate' => 10],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['brackets.0.max_amount']);

        $this->putJson('/api/v1/payroll-rules/tax-brackets', ['brackets' => [
            ['min_amount' => 0, 'max_amount' => 1000000, 'rate' => 0],
            ['min_amount' => 1000000, 'max_amount' => null, 'rate' => 10],
        ]])->assertOk()->assertJsonCount(2, 'data.tax_brackets');

        $this->assertSame(100000.0, app(PayrollRules::class)->tax(2000000, new StaffPayrollProfile)['tax']);
    }

    public function test_staff_profiles_default_to_resident_and_enrolled_until_saved(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create();

        $row = collect($this->getJson('/api/v1/staff-payroll-profiles')->assertOk()->json('data'))->firstWhere('staff.id', $staff->id);
        $this->assertSame(['saved' => false, 'tax_resident' => true, 'spouse_dependent' => false, 'child_dependents' => 0, 'social_security_enrolled' => true, 'social_security_number' => null], $row['profile']);

        $this->putJson("/api/v1/staff-payroll-profiles/{$staff->id}", ['spouse_dependent' => true, 'child_dependents' => 2, 'social_security_number' => 'N-123'])
            ->assertOk()
            ->assertJsonPath('data.profile.saved', true)
            ->assertJsonPath('data.profile.child_dependents', 2);
    }

    public function test_overtime_is_paid_by_the_kind_of_day(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = $this->staffOn520();
        $month = CarbonImmutable::now()->subMonth()->startOfMonth();
        $monday = $month->next(CarbonImmutable::MONDAY);
        $holiday = $monday->addDays(2);
        $sunday = $monday->addDays(6);
        Holiday::query()->create(['name' => 'Holiday', 'start_date' => $holiday->toDateString(), 'end_date' => $holiday->toDateString()]);

        foreach ([[$monday, 120], [$holiday, 60], [$sunday, 30], [$monday->addDay(), 600, 'pending']] as $row) {
            OvertimeRequest::query()->create(['staff_id' => $staff->id, 'date' => $row[0]->toDateString(), 'minutes' => $row[1], 'reason' => 'Exams', 'status' => $row[2] ?? 'approved', 'requested_by' => 1]);
        }

        $rows = $this->getJson('/api/v1/payroll-overtime?month='.$month->format('Y-m'))->assertOk()->json('data.rows');

        $this->assertCount(1, $rows);
        $this->assertSame(['normal' => 120, 'rest_day' => 30, 'holiday' => 60], $rows[0]['minutes']);
        // 2h × $2.50 × 1.5 + 0.5h × $2.50 × 2 + 1h × $2.50 × 2 = 7.50 + 2.50 + 5.00.
        $this->assertSame(15.0, (float) $rows[0]['amount']);
    }

    public function test_attendance_deducts_absence_unpaid_leave_and_late_minutes_past_the_grace(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $shift = Shift::factory()->create();
        WorkSchedule::factory()->create(['is_default' => true, 'monday_shift_id' => $shift->id]);
        $staff = $this->staffOn520();
        PayrollSetting::current()->update(['late_grace_minutes' => 10]);

        $month = CarbonImmutable::now()->subMonth()->startOfMonth();
        $mondays = collect(range(0, $month->daysInMonth - 1))->map(fn ($d) => $month->addDays($d))->filter->isMonday()->values();

        // First Monday: in, 30 minutes late. Second: unpaid leave. Every other Monday: absent.
        StaffAttendance::query()->create(['staff_id' => $staff->id, 'date' => $mondays[0]->toDateString(), 'status' => 'late', 'late_minutes' => 30, 'worked_minutes' => 450]);
        $unpaid = LeaveType::factory()->create(['is_paid' => false]);
        LeaveRequest::query()->create(['staff_id' => $staff->id, 'leave_type_id' => $unpaid->id, 'from_date' => $mondays[1]->toDateString(), 'to_date' => $mondays[1]->toDateString(), 'reason' => 'Personal', 'status' => LeaveRequest::STATUS_APPROVED]);
        $absent = $mondays->count() - 2;

        $row = collect($this->getJson('/api/v1/payroll-attendance-deductions?month='.$month->format('Y-m'))->assertOk()->json('data.rows'))->firstWhere('staff.id', $staff->id);

        $this->assertSame($absent, $row['absent_days']);
        $this->assertEquals(1, $row['unpaid_leave_days']);
        $this->assertSame(20, $row['late_minutes']);
        $this->assertEquals($absent * 20, $row['lines']['absence']);
        $this->assertEquals(20, $row['lines']['unpaid_leave']);
        $this->assertEquals(0.83, $row['lines']['late']); // 20 min × $2.50 / 60
        $this->assertEquals($absent * 20 + 20 + 0.83, $row['amount']);
    }

    public function test_a_loan_is_in_the_salary_currency_and_settles_when_paid_back(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $noSalary = Staff::factory()->create();
        $staff = $this->staffOn520();

        $base = ['type' => 'advance', 'amount' => 200, 'issued_on' => now()->toDateString(), 'installment_amount' => 100, 'first_deduction_on' => now()->addMonth()->toDateString()];
        $this->postJson('/api/v1/staff-loans', [...$base, 'staff_id' => $noSalary->id])->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
        $this->postJson('/api/v1/staff-loans', [...$base, 'staff_id' => $staff->id, 'installment_amount' => 300])->assertUnprocessable()->assertJsonValidationErrors(['installment_amount']);

        $loan = $this->postJson('/api/v1/staff-loans', [...$base, 'staff_id' => $staff->id])
            ->assertCreated()->assertJsonPath('data.currency', 'USD')->assertJsonPath('data.balance', 200)->json('data');

        $this->postJson("/api/v1/staff-loans/{$loan['id']}/repayments", ['amount' => 250, 'paid_on' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->postJson("/api/v1/staff-loans/{$loan['id']}/repayments", ['amount' => 120, 'paid_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.balance', 80)->assertJsonPath('data.status', 'active');
        $paidOff = $this->postJson("/api/v1/staff-loans/{$loan['id']}/repayments", ['amount' => 80, 'paid_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'settled')->json('data');

        // Taking a repayment back reopens it; one with repayments can't be deleted.
        $this->deleteJson("/api/v1/staff-loan-repayments/{$paidOff['repayments'][0]['id']}")->assertOk()->assertJsonPath('data.status', 'active');
        $this->deleteJson("/api/v1/staff-loans/{$loan['id']}")->assertUnprocessable();

        $this->postJson("/api/v1/staff-loans/{$loan['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.balance', 0);
        $this->postJson("/api/v1/staff-loans/{$loan['id']}/repayments", ['amount' => 1, 'paid_on' => now()->toDateString()])->assertUnprocessable();

        $this->getJson('/api/v1/staff-loans?filter[status]=cancelled')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_loan_cannot_drop_below_what_is_paid_back(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $loan = StaffLoan::factory()->create(['amount' => 300, 'installment_amount' => 100]);
        $loan->repayments()->create(['amount' => 150, 'paid_on' => now()->toDateString(), 'method' => 'cash']);

        $this->putJson("/api/v1/staff-loans/{$loan->id}", ['amount' => 100])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->putJson("/api/v1/staff-loans/{$loan->id}", ['amount' => 150])->assertOk()->assertJsonPath('data.status', 'settled');
    }
}
