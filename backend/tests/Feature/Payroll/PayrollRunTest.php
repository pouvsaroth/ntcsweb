<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\Account;
use App\Models\Expense;
use App\Models\PayrollComponent;
use App\Models\PayrollRun;
use App\Models\SalaryStructure;
use App\Models\Staff;
use App\Models\StaffLoan;
use App\Models\StaffPayComponent;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Accounting\AccountType;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Payroll's runs (stage 3) — working out payslips, approval, paying
 * (Accounting + loans), and 15-day payrolls. Riel salaries throughout, so
 * no currency rate is needed and the riel tax/NSSF figures read as they are.
 */
class PayrollRunTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PAYROLL_VIEW, Permissions::PAYROLL_MANAGE, Permissions::PAYROLL_RUN, Permissions::PAYROLL_APPROVE];

    private string $month;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsAdminWithPermissions(self::ALL);
        $this->month = CarbonImmutable::now()->subMonth()->format('Y-m');
    }

    /** 2,000,000៛ a month, hired well before the period. */
    private function staffOnTwoMillion(array $salary = []): Staff
    {
        $staff = Staff::factory()->create(['hire_date' => now()->subYears(2)->toDateString()]);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 2000000, 'currency' => Tenant::CURRENCY_KHR, 'effective_from' => now()->subYears(2)->toDateString(), ...$salary]);

        return $staff;
    }

    private function createRun(string $period = 'month'): array
    {
        return $this->postJson('/api/v1/payroll-runs', ['month' => $this->month, 'period' => $period, 'pay_date' => now()->toDateString()])
            ->assertCreated()
            ->json('data');
    }

    public function test_a_monthly_payslip_adds_up_earnings_deductions_nssf_tax_and_a_loan(): void
    {
        $transport = PayrollComponent::factory()->create(['code' => 'TR', 'name' => 'Transport', 'affects_tax' => true, 'affects_social_security' => false]);
        $bonus = PayrollComponent::factory()->create(['code' => 'BN', 'kind' => PayrollComponent::KIND_BONUS, 'affects_tax' => true]);
        $uniform = PayrollComponent::factory()->create(['code' => 'UN', 'kind' => PayrollComponent::KIND_DEDUCTION, 'affects_tax' => false]);
        $structure = SalaryStructure::factory()->create(['currency' => Tenant::CURRENCY_KHR]);
        $structure->items()->create(['payroll_component_id' => $transport->id, 'amount' => 100000]);

        $staff = $this->staffOnTwoMillion(['salary_structure_id' => $structure->id]);
        $start = CarbonImmutable::parse($this->month.'-01');
        StaffPayComponent::factory()->create(['staff_id' => $staff->id, 'payroll_component_id' => $bonus->id, 'amount' => 200000, 'recurrence' => 'once', 'starts_on' => $start->addDays(9)->toDateString()]);
        StaffPayComponent::factory()->create(['staff_id' => $staff->id, 'payroll_component_id' => $uniform->id, 'amount' => 50000, 'starts_on' => $start->subYear()->toDateString()]);
        $loan = StaffLoan::factory()->create(['staff_id' => $staff->id, 'currency' => Tenant::CURRENCY_KHR, 'amount' => 500000, 'installment_amount' => 300000, 'issued_on' => $start->subMonth()->toDateString(), 'first_deduction_on' => $start->toDateString()]);

        $slip = collect($this->createRun()['payslips'])->firstWhere('staff.id', $staff->id);

        $this->assertSame(2000000.0, (float) $slip['basic_pay']);
        $this->assertSame(100000.0, (float) $slip['allowances']);
        $this->assertSame(200000.0, (float) $slip['bonuses']);
        $this->assertSame(2300000.0, (float) $slip['gross_pay']);
        $this->assertSame(50000.0, (float) $slip['other_deductions']);
        // NSSF on basic only (transport doesn't count), capped at 1,200,000: pension 2% = 24,000; school 5.4% = 64,800.
        $this->assertSame(24000.0, (float) $slip['social_security_employee']);
        $this->assertSame(64800.0, (float) $slip['social_security_employer']);
        // Tax on 2,300,000 − 24,000 = 2,276,000: 500,000 × 5% + 276,000 × 10%.
        $this->assertSame(52600.0, (float) $slip['tax']);
        $this->assertSame(300000.0, (float) $slip['loan_deduction']);
        $this->assertSame(1873400.0, (float) $slip['net_pay']);
        $this->assertSame($loan->id, collect($slip['lines']['deductions'])->firstWhere('type', 'loan')['loan_id']);
    }

    public function test_a_pay_period_is_held_by_one_run_until_it_is_cancelled(): void
    {
        $this->staffOnTwoMillion();
        $run = $this->createRun();
        $this->assertSame('PR-'.$this->month, $run['reference']);

        $this->postJson('/api/v1/payroll-runs', ['month' => $this->month, 'period' => 'first_half', 'pay_date' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['period']);

        $this->postJson("/api/v1/payroll-runs/{$run['id']}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame('PR-'.$this->month.'-2', $this->createRun()['reference']);
    }

    public function test_a_15_day_payroll_takes_tax_and_nssf_on_the_whole_month_in_the_second_half(): void
    {
        $staff = $this->staffOnTwoMillion();

        $first = collect($this->createRun('first_half')['payslips'])->firstWhere('staff.id', $staff->id);
        $this->assertSame(1000000.0, (float) $first['basic_pay']);
        $this->assertSame(0.0, (float) $first['tax']);
        $this->assertSame(0.0, (float) $first['social_security_employee']);
        $this->assertSame(1000000.0, (float) $first['net_pay']);

        // The month: 2,000,000 → NSSF 24,000; tax on 1,976,000 = 23,800.
        $second = collect($this->createRun('second_half')['payslips'])->firstWhere('staff.id', $staff->id);
        $this->assertSame(1000000.0, (float) $second['basic_pay']);
        $this->assertSame(24000.0, (float) $second['social_security_employee']);
        $this->assertSame(23800.0, (float) $second['tax']);
        $this->assertSame(952200.0, (float) $second['net_pay']);
    }

    public function test_an_adjustment_changes_the_net_and_survives_a_recalculation(): void
    {
        $staff = $this->staffOnTwoMillion();
        $run = $this->createRun();
        $slip = collect($run['payslips'])->firstWhere('staff.id', $staff->id);
        $net = (float) $slip['net_pay'];

        $this->putJson("/api/v1/payslips/{$slip['id']}/adjustment", ['adjustment' => 100000, 'adjustment_note' => 'Exam marking'])->assertOk();
        $after = collect($this->postJson("/api/v1/payroll-runs/{$run['id']}/recalculate")->assertOk()->json('data.payslips'))->firstWhere('staff.id', $staff->id);

        $this->assertSame($net + 100000, (float) $after['net_pay']);
        $this->assertSame('Exam marking', collect($after['lines']['earnings'])->firstWhere('type', 'adjustment')['name']);
    }

    public function test_it_is_approved_then_paid_into_accounting_and_takes_the_loan_installment(): void
    {
        $this->tenant->update(['default_currency' => Tenant::CURRENCY_KHR]);
        // The signed-in admin's cached tenant is what each request resolves.
        $this->admin->setRelation('tenant', $this->tenant->fresh());
        $salary = Account::factory()->type(AccountType::EXPENSE)->create(['code' => '5100', 'name' => 'Salary']);
        $cash = Account::factory()->bankOrCash()->create(['code' => '1100', 'name' => 'Cash']);
        $staff = $this->staffOnTwoMillion();
        $loan = StaffLoan::factory()->create(['staff_id' => $staff->id, 'currency' => Tenant::CURRENCY_KHR, 'amount' => 500000, 'installment_amount' => 300000, 'issued_on' => now()->subMonths(3)->toDateString(), 'first_deduction_on' => $this->month.'-01']);
        $approver = User::factory()->forTenant($this->tenant)->create();
        $approver->attachRoles(\App\Models\Role::query()->where('slug', 'test-admin')->firstOrFail());

        $run = $this->createRun();
        $net = (float) collect($run['payslips'])->sum('net_pay');

        // Can't pay before it's approved.
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/pay", ['expense_account_id' => $salary->id, 'cash_account_id' => $cash->id, 'paid_on' => now()->toDateString()])
            ->assertUnprocessable();

        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
        $this->assertSame(1, UserNotification::where('recipient_id', $approver->id)->where('type', NotificationType::PAYROLL_RUN_SUBMITTED)->count());
        // Fixed once sent.
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/recalculate")->assertUnprocessable();

        $this->actingAsTenantUser($approver);
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSame(1, UserNotification::where('recipient_id', $this->admin->id)->where('type', NotificationType::PAYROLL_RUN_APPROVED)->count());

        $this->actingAsTenantUser($this->admin);
        $paid = $this->postJson("/api/v1/payroll-runs/{$run['id']}/pay", ['expense_account_id' => $salary->id, 'cash_account_id' => $cash->id, 'paid_on' => now()->toDateString()])
            ->assertOk()->assertJsonPath('data.status', 'paid')->json('data');

        $expense = Expense::query()->findOrFail($paid['expense']['id']);
        $this->assertEquals($net, (float) $expense->amount);
        $this->assertSame('PAID', $expense->status);
        $this->assertSame(PayrollRun::class, $expense->reference_type);
        $this->assertEquals(200000, $loan->fresh()->balance());
        $this->assertSame('payroll', $loan->repayments()->first()->method);
    }

    public function test_a_rejected_run_goes_back_to_be_fixed_and_sent_again(): void
    {
        $this->staffOnTwoMillion();
        $run = $this->createRun();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk();

        $this->postJson("/api/v1/payroll-runs/{$run['id']}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/reject", ['reason' => 'Overtime missing'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Overtime missing');

        $this->postJson("/api/v1/payroll-runs/{$run['id']}/recalculate")->assertOk();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk()->assertJsonPath('data.status', 'pending');
    }

    public function test_approving_needs_the_approve_permission(): void
    {
        $this->staffOnTwoMillion();
        $run = $this->createRun();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk();

        $clerk = User::factory()->forTenant($this->tenant)->create();
        $role = \App\Models\Role::factory()->forTenant($this->tenant)->create(['slug' => 'payroll-clerk', 'level' => 50]);
        $role->permissions()->attach(\App\Models\Permission::query()->whereIn('slug', [Permissions::PAYROLL_VIEW, Permissions::PAYROLL_RUN])->pluck('id'));
        $clerk->attachRoles($role);

        $this->actingAsTenantUser($clerk);
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertForbidden();
    }

    public function test_usd_salaries_need_a_currency_rate_for_tax(): void
    {
        $staff = Staff::factory()->create(['hire_date' => now()->subYear()->toDateString()]);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 500, 'currency' => Tenant::CURRENCY_USD, 'effective_from' => now()->subYear()->toDateString()]);

        $this->postJson('/api/v1/payroll-runs', ['month' => $this->month, 'period' => 'month', 'pay_date' => now()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['khr_per_usd']);

        // The first half takes no tax, so it doesn't need one.
        $this->postJson('/api/v1/payroll-runs', ['month' => $this->month, 'period' => 'first_half', 'pay_date' => now()->toDateString()])->assertCreated();
    }

    public function test_history_lists_a_staff_members_payslips_across_runs(): void
    {
        $staff = $this->staffOnTwoMillion();
        $this->createRun('first_half');
        $this->createRun('second_half');

        $this->getJson("/api/v1/payslips?filter[staff_id]={$staff->id}")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/payroll-runs?year='.substr($this->month, 0, 4))->assertOk()->assertJsonCount(2, 'data');
    }
}
