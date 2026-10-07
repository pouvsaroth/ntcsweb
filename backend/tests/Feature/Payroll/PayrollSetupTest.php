<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\PayrollComponent;
use App\Models\SalaryStructure;
use App\Models\Staff;
use App\Models\StaffPayComponent;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Payroll's set-up (stage 1) — pay components, salary structures,
 * staff salaries, and the allowances/bonuses/deductions given to staff.
 */
class PayrollSetupTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PAYROLL_VIEW, Permissions::PAYROLL_MANAGE];

    public function test_viewing_needs_view_and_changing_needs_manage(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $this->getJson('/api/v1/payroll-components')->assertForbidden();
        $this->getJson('/api/v1/staff-salaries')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW]);
        $this->getJson('/api/v1/payroll-components')->assertOk();
        $this->getJson('/api/v1/salary-structures')->assertOk();
        $this->getJson('/api/v1/staff-salaries')->assertOk();
        $this->getJson('/api/v1/staff-pay-components')->assertOk();
        $this->postJson('/api/v1/payroll-components', ['kind' => 'allowance', 'code' => 'TR', 'name' => 'Transport'])->assertForbidden();
    }

    public function test_components_are_listed_by_kind_with_unique_codes_and_a_fixed_kind(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);

        $allowance = $this->postJson('/api/v1/payroll-components', ['kind' => 'allowance', 'code' => 'TR', 'name' => 'Transport'])
            ->assertCreated()
            ->assertJsonPath('data.calculation', 'fixed')
            ->assertJsonPath('data.affects_tax', true)
            ->json('data');
        $this->postJson('/api/v1/payroll-components', ['kind' => 'deduction', 'code' => 'UNI', 'name' => 'Uniform', 'calculation' => 'percent_of_basic'])->assertCreated();
        $this->postJson('/api/v1/payroll-components', ['kind' => 'salary', 'code' => 'TR', 'name' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind', 'code']);

        $this->getJson('/api/v1/payroll-components?filter[kind]=allowance')->assertOk()->assertJsonCount(1, 'data');

        $this->putJson("/api/v1/payroll-components/{$allowance['id']}", ['kind' => 'deduction'])
            ->assertUnprocessable()->assertJsonValidationErrors(['kind']);
    }

    public function test_a_component_in_use_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $used = PayrollComponent::factory()->create();
        $unused = PayrollComponent::factory()->create();
        StaffPayComponent::factory()->create(['payroll_component_id' => $used->id]);

        $this->deleteJson("/api/v1/payroll-components/{$used->id}")->assertUnprocessable();
        $this->deleteJson("/api/v1/payroll-components/{$unused->id}")->assertNoContent();
    }

    public function test_a_salary_structure_saves_and_replaces_its_items(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $transport = PayrollComponent::factory()->create();
        $housing = PayrollComponent::factory()->create(['calculation' => PayrollComponent::CALCULATION_PERCENT_OF_BASIC]);

        $structure = $this->postJson('/api/v1/salary-structures', [
            'code' => 'FT', 'name' => 'Full-time teacher', 'currency' => 'USD',
            'items' => [
                ['payroll_component_id' => $transport->id, 'amount' => 30],
                ['payroll_component_id' => $housing->id, 'amount' => 10],
            ],
        ])->assertCreated()->assertJsonCount(2, 'data.items')->json('data');

        $this->putJson("/api/v1/salary-structures/{$structure['id']}", [
            'items' => [['payroll_component_id' => $transport->id, 'amount' => 40]],
        ])->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.amount', '40.00');

        // A percentage over 100, and the same component twice.
        $this->putJson("/api/v1/salary-structures/{$structure['id']}", [
            'items' => [
                ['payroll_component_id' => $housing->id, 'amount' => 150],
                ['payroll_component_id' => $housing->id, 'amount' => 5],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.payroll_component_id']);
        $this->putJson("/api/v1/salary-structures/{$structure['id']}", [
            'items' => [['payroll_component_id' => $housing->id, 'amount' => 150]],
        ])->assertUnprocessable()->assertJsonValidationErrors(['items.0.amount']);
    }

    public function test_a_structure_used_by_salaries_keeps_its_currency_and_cannot_be_deleted(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $structure = SalaryStructure::factory()->create(['currency' => Tenant::CURRENCY_USD]);
        StaffSalary::factory()->create(['salary_structure_id' => $structure->id]);

        $this->putJson("/api/v1/salary-structures/{$structure->id}", ['currency' => 'KHR'])
            ->assertUnprocessable()->assertJsonValidationErrors(['currency']);
        $this->deleteJson("/api/v1/salary-structures/{$structure->id}")->assertUnprocessable();
    }

    public function test_the_salary_list_shows_the_one_in_effect_today_and_a_raise_still_to_come(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create(['employee_code' => 'E001']);
        $unpaid = Staff::factory()->create(['employee_code' => 'E002']);

        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 400, 'effective_from' => now()->subYear()->toDateString()]);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 450, 'effective_from' => now()->subMonth()->toDateString()]);
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 500, 'effective_from' => now()->addMonth()->toDateString()]);

        $rows = collect($this->getJson('/api/v1/staff-salaries')->assertOk()->json('data'))->keyBy('staff.id');

        $this->assertSame('450.00', $rows[$staff->id]['salary']['basic_salary']);
        $this->assertSame('500.00', $rows[$staff->id]['next_salary']['basic_salary']);
        $this->assertNull($rows[$unpaid->id]['salary']);

        $missing = collect($this->getJson('/api/v1/staff-salaries?missing_only=1')->assertOk()->json('data'))->pluck('staff.id')->all();
        $this->assertSame([$unpaid->id], $missing);

        $this->getJson("/api/v1/staff-salaries/history/{$staff->id}")->assertOk()->assertJsonCount(3, 'data.salaries');
    }

    public function test_a_salary_is_one_per_date_and_its_structure_must_match_its_currency(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create();
        $khrStructure = SalaryStructure::factory()->create(['currency' => Tenant::CURRENCY_KHR]);
        $date = now()->startOfMonth()->toDateString();

        $this->postJson('/api/v1/staff-salaries', [
            'staff_id' => $staff->id, 'basic_salary' => 500, 'currency' => 'USD', 'effective_from' => $date,
        ])->assertCreated()->assertJsonPath('data.payment_method', 'bank');

        $this->postJson('/api/v1/staff-salaries', [
            'staff_id' => $staff->id, 'basic_salary' => 550, 'currency' => 'USD', 'effective_from' => $date,
        ])->assertUnprocessable()->assertJsonValidationErrors(['effective_from']);

        $this->postJson('/api/v1/staff-salaries', [
            'staff_id' => $staff->id, 'basic_salary' => 550, 'currency' => 'USD',
            'effective_from' => now()->addMonth()->startOfMonth()->toDateString(), 'salary_structure_id' => $khrStructure->id,
        ])->assertUnprocessable()->assertJsonValidationErrors(['salary_structure_id']);
    }

    public function test_staff_are_given_recurring_and_one_time_items(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $staff = Staff::factory()->create();
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'currency' => Tenant::CURRENCY_KHR, 'effective_from' => now()->subMonth()->toDateString()]);
        $transport = PayrollComponent::factory()->create();
        $bonus = PayrollComponent::factory()->create(['kind' => PayrollComponent::KIND_BONUS]);

        $this->postJson('/api/v1/staff-pay-components', [
            'staff_id' => $staff->id, 'payroll_component_id' => $transport->id, 'amount' => 40000,
            'starts_on' => now()->startOfMonth()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.recurrence', 'recurring')->assertJsonPath('data.currency', 'KHR');

        $this->postJson('/api/v1/staff-pay-components', [
            'staff_id' => $staff->id, 'payroll_component_id' => $bonus->id, 'amount' => 100000, 'recurrence' => 'once',
            'starts_on' => now()->toDateString(), 'ends_on' => now()->addMonth()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['ends_on']);

        $this->postJson('/api/v1/staff-pay-components', [
            'staff_id' => $staff->id, 'payroll_component_id' => $bonus->id, 'amount' => 100000, 'recurrence' => 'once',
            'starts_on' => now()->toDateString(),
        ])->assertCreated();

        $this->getJson('/api/v1/staff-pay-components?kind=bonus')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/staff-pay-components?filter[staff_id]={$staff->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_current_keeps_only_items_still_in_effect(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $ended = StaffPayComponent::factory()->create(['starts_on' => now()->subYear()->toDateString(), 'ends_on' => now()->subMonth()->toDateString()]);
        $running = StaffPayComponent::factory()->create(['starts_on' => now()->subYear()->toDateString()]);
        $pastBonus = StaffPayComponent::factory()->create(['recurrence' => StaffPayComponent::ONCE, 'starts_on' => now()->subMonths(2)->toDateString()]);

        $ids = collect($this->getJson('/api/v1/staff-pay-components?current=1')->assertOk()->json('data'))->pluck('id')->all();

        $this->assertSame([$running->id], $ids);
        $this->assertNotContains($ended->id, $ids);
        $this->assertNotContains($pastBonus->id, $ids);
    }
}
