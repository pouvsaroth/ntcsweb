<?php

declare(strict_types=1);

namespace Tests\Feature\Payroll;

use App\Models\ApprovalFlowStep;
use App\Models\ApprovalGroup;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payroll\PayslipPdfService;
use App\Support\Approvals\DocumentType;
use App\Support\Authorization\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** HRM > Payroll > Payslip > Export PDF: many payslips in one PDF, signed per the approval flow. */
class PayslipPdfTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PAYROLL_VIEW, Permissions::PAYROLL_MANAGE, Permissions::PAYROLL_RUN, Permissions::PAYROLL_APPROVE];

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->actingAsAdminWithPermissions(self::ALL);
    }

    /** @return array<string, mixed> a run with two staff */
    private function runWithTwoStaff(): array
    {
        foreach ([1, 2] as $i) {
            $staff = Staff::factory()->create(['hire_date' => now()->subYears(2)->toDateString()]);
            StaffSalary::factory()->create(['staff_id' => $staff->id, 'basic_salary' => 2000000, 'currency' => Tenant::CURRENCY_KHR, 'effective_from' => now()->subYears(2)->toDateString()]);
        }

        return $this->postJson('/api/v1/payroll-runs', ['month' => CarbonImmutable::now()->subMonth()->format('Y-m'), 'period' => 'month', 'pay_date' => now()->toDateString()])
            ->assertCreated()->json('data');
    }

    private function approver(string $groupName, int $order): User
    {
        $user = User::factory()->forTenant($this->tenant)->create(['name' => $groupName.' approver']);
        $user->attachRoles(Role::query()->where('slug', 'test-admin')->firstOrFail());
        $group = ApprovalGroup::factory()->create(['name' => $groupName]);
        $group->members()->create(['user_id' => $user->id]);
        ApprovalFlowStep::query()->create(['document_type' => DocumentType::PAYROLL_RUN, 'step_order' => $order, 'approval_group_id' => $group->id]);

        return $user;
    }

    public function test_the_picked_payslips_download_as_one_pdf(): void
    {
        $run = $this->runWithTwoStaff();
        $first = $run['payslips'][0]['id'];

        $response = $this->get("/api/v1/payroll-runs/{$run['id']}/payslips-pdf?ids={$first}&locale=km")->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertStringContainsString('payslips-', (string) $response->headers->get('Content-Disposition'));

        // All of them when none are picked.
        $this->get("/api/v1/payroll-runs/{$run['id']}/payslips-pdf")->assertOk();
    }

    public function test_signatures_follow_the_approval_flow_steps(): void
    {
        $hr = $this->approver('HR', 1);
        $director = $this->approver('Director', 2);
        $run = $this->runWithTwoStaff();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk();

        $model = PayrollRun::query()->findOrFail($run['id']);
        $signers = app(PayslipPdfService::class)->signers($model);
        $this->assertSame(['Prepared by', 'HR', 'Director'], array_column($signers, 'title'));
        $this->assertSame($this->admin->name, $signers[0]['name']);
        // Not approved yet: the step boxes are blank.
        $this->assertNull($signers[1]['name']);

        $this->actingAsTenantUser($hr);
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertOk();
        $this->actingAsTenantUser($director);
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $signers = app(PayslipPdfService::class)->signers($model->fresh());
        $this->assertSame([$this->admin->name, 'HR approver', 'Director approver'], array_column($signers, 'name'));
        $this->assertSame(now()->format('d-m-Y'), $signers[2]['date']);
    }

    public function test_without_a_flow_the_final_approver_signs(): void
    {
        $run = $this->runWithTwoStaff();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/submit")->assertOk();
        $this->postJson("/api/v1/payroll-runs/{$run['id']}/approve")->assertOk();

        $signers = app(PayslipPdfService::class)->signers(PayrollRun::query()->findOrFail($run['id']));
        $this->assertSame(['Prepared by', 'Approved by'], array_column($signers, 'title'));
        $this->assertSame($this->admin->name, $signers[1]['name']);
    }

    public function test_exporting_needs_payroll_view(): void
    {
        $run = $this->runWithTwoStaff();

        $outsider = User::factory()->forTenant($this->tenant)->create();
        $role = Role::factory()->forTenant($this->tenant)->create(['slug' => 'no-payroll', 'level' => 50]);
        $role->permissions()->attach(Permission::query()->where('slug', Permissions::INVOICES_VIEW)->pluck('id'));
        $outsider->attachRoles($role);
        $this->actingAsTenantUser($outsider);

        $this->get("/api/v1/payroll-runs/{$run['id']}/payslips-pdf")->assertForbidden();
    }
}
