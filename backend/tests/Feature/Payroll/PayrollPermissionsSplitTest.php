<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\PayrollComponent;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * Roles step 3a: HRM > Payroll's Add / Update / Delete are per tab
 * (payroll-<tab>.create|update|delete) instead of one payroll.manage.
 */
class PayrollPermissionsSplitTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    public function test_allowances_bonuses_and_deductions_are_separate_tabs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_ALLOWANCES_CREATE]);
        $this->postJson('/api/v1/payroll-components', ['kind' => 'allowance', 'code' => 'TR', 'name' => 'Transport'])->assertCreated();
        $this->postJson('/api/v1/payroll-components', ['kind' => 'bonus', 'code' => 'BN', 'name' => 'Bonus'])->assertForbidden();
        $this->postJson('/api/v1/payroll-components', ['kind' => 'deduction', 'code' => 'UN', 'name' => 'Uniform'])->assertForbidden();

        $bonus = PayrollComponent::factory()->create(['kind' => PayrollComponent::KIND_BONUS]);
        $this->putJson("/api/v1/payroll-components/{$bonus->id}", ['name' => 'Year-end'])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_BONUSES_UPDATE]);
        $this->putJson("/api/v1/payroll-components/{$bonus->id}", ['name' => 'Year-end'])->assertOk();
        $this->deleteJson("/api/v1/payroll-components/{$bonus->id}")->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_BONUSES_DELETE]);
        $this->deleteJson("/api/v1/payroll-components/{$bonus->id}")->assertNoContent();
    }

    public function test_giving_an_item_to_staff_follows_the_items_tab(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_DEDUCTIONS_CREATE]);
        $staff = Staff::factory()->create();
        StaffSalary::factory()->create(['staff_id' => $staff->id, 'currency' => Tenant::CURRENCY_KHR, 'effective_from' => now()->subMonth()->toDateString()]);
        $transport = PayrollComponent::factory()->create();
        $uniform = PayrollComponent::factory()->create(['kind' => PayrollComponent::KIND_DEDUCTION]);
        $give = fn (PayrollComponent $c) => ['staff_id' => $staff->id, 'payroll_component_id' => $c->id, 'amount' => 1000, 'starts_on' => now()->startOfMonth()->toDateString()];

        $this->postJson('/api/v1/staff-pay-components', $give($transport))->assertForbidden();
        $this->postJson('/api/v1/staff-pay-components', $give($uniform))->assertCreated();
    }

    public function test_settings_tabs_need_their_own_update(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_OVERTIME_UPDATE]);
        $this->putJson('/api/v1/payroll-rules/settings', ['overtime_normal_rate' => 1.5, 'hours_per_day' => 8])->assertOk();
        $this->putJson('/api/v1/payroll-rules/settings', ['deduct_absence' => false])->assertForbidden();
        $this->putJson('/api/v1/payroll-rules/settings', ['tax_child_allowance' => 150000])->assertForbidden();
        // A mix needs every tab it touches.
        $this->putJson('/api/v1/payroll-rules/settings', ['hours_per_day' => 8, 'late_grace_minutes' => 5])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_ATTENDANCE_DEDUCTION_UPDATE]);
        $this->putJson('/api/v1/payroll-rules/settings', ['deduct_absence' => false, 'late_grace_minutes' => 5])->assertOk();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_TAX_UPDATE]);
        $this->putJson('/api/v1/payroll-rules/settings', ['tax_child_allowance' => 150000])->assertOk();
    }

    public function test_staff_profile_tax_and_nssf_fields_follow_their_tabs(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_TAX_UPDATE]);
        $staff = Staff::factory()->create();
        $this->putJson("/api/v1/staff-payroll-profiles/{$staff->id}", ['child_dependents' => 2])->assertOk();
        $this->putJson("/api/v1/staff-payroll-profiles/{$staff->id}", ['social_security_enrolled' => false])->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW, Permissions::PAYROLL_SOCIAL_SECURITY_UPDATE]);
        $this->putJson("/api/v1/staff-payroll-profiles/{$staff->id}", ['social_security_enrolled' => false])->assertOk();
    }

    public function test_view_alone_changes_nothing(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::PAYROLL_VIEW]);
        $this->getJson('/api/v1/payroll-components?kind=allowance')->assertOk();
        $this->postJson('/api/v1/staff-loans', [])->assertForbidden();
        $this->putJson('/api/v1/payroll-rules/tax-brackets', ['brackets' => []])->assertForbidden();
    }

    public function test_school_admin_has_every_payroll_tab_permission_by_default(): void
    {
        $defaults = Permissions::defaultsForSystemRoles()['school-admin'];
        foreach ([
            Permissions::PAYROLL_SALARY_STRUCTURES_DELETE, Permissions::PAYROLL_BASIC_SALARY_DELETE, Permissions::PAYROLL_ALLOWANCES_DELETE,
            Permissions::PAYROLL_BONUSES_DELETE, Permissions::PAYROLL_DEDUCTIONS_DELETE, Permissions::PAYROLL_OVERTIME_UPDATE,
            Permissions::PAYROLL_ATTENDANCE_DEDUCTION_UPDATE, Permissions::PAYROLL_TAX_UPDATE, Permissions::PAYROLL_SOCIAL_SECURITY_DELETE,
            Permissions::PAYROLL_LOANS_DELETE,
        ] as $slug) {
            $this->assertContains($slug, $defaults);
        }
    }
}
