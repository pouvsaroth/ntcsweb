<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Tenant;
use App\Platform\Models\Plan;
use App\Platform\Models\School;
use App\Platform\Models\SchoolSubscription;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\HasAcademicAdmin;
use Tests\Concerns\HasAcademicCatalog;
use Tests\Concerns\HasPlatformStaff;
use Tests\TestCase;

/**
 * Stage 5e (docs/backoffice/stage-5-billing.md): expire, warn, suspend after
 * grace, and lift a billing suspension when the school pays.
 */
class BillingSuspensionTest extends TestCase
{
    use HasAcademicAdmin, HasAcademicCatalog, HasPlatformStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-18 05:00:00', 'UTC')); // noon in Phnom Penh
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function plan(array $overrides = []): Plan
    {
        return Plan::query()->create($overrides + [
            'code' => 'p-'.fake()->unique()->numberBetween(1, 99999), 'name' => 'Basic', 'price' => 50, 'currency' => 'USD',
            'period_months' => 1, 'modules' => \App\Platform\Billing\Modules::codes(), 'status' => Plan::STATUS_ACTIVE,
        ]);
    }

    private function school(string $status, ?string $end, ?Plan $plan = null): Tenant
    {
        $tenant = Tenant::factory()->create();
        SchoolSubscription::query()->create([
            'tenant_id' => $tenant->id, 'plan_id' => ($plan ?? $this->plan())->id, 'status' => $status,
            'start_on' => '2026-08-01', 'end_on' => $end,
        ]);

        return $tenant;
    }

    private function tick(): void
    {
        $this->artisan('platform:billing-tick')->assertSuccessful();
    }

    public function test_a_plan_past_its_end_expires_but_the_school_keeps_working_through_grace(): void
    {
        $school = $this->school('active', '2026-10-17'); // ended yesterday
        $current = $this->school('active', '2026-10-18'); // ends today: still covered

        $this->tick();

        $this->assertSame('expired', SchoolSubscription::query()->where('tenant_id', $school->id)->value('status'));
        $this->assertSame('active', SchoolSubscription::query()->where('tenant_id', $current->id)->value('status'));
        $this->assertSame(Tenant::STATUS_ACTIVE, $school->fresh()->status);
        $this->assertDatabaseHas('bo_audit_logs', ['action' => 'billing.expired', 'tenant_id' => $school->id]);
    }

    public function test_after_the_grace_days_the_school_is_suspended_for_billing(): void
    {
        $inGrace = $this->school('expired', '2026-10-11'); // 7th grace day today
        $past = $this->school('expired', '2026-10-10');    // grace over

        $this->tick();

        $this->assertSame(Tenant::STATUS_ACTIVE, $inGrace->fresh()->status);
        $this->assertSame(Tenant::STATUS_SUSPENDED, $past->fresh()->status);
        $this->assertNotNull(School::for($past)->billing_suspended_at);
        $this->assertDatabaseHas('bo_school_status_history', ['tenant_id' => $past->id, 'to_status' => 'suspended', 'staff_id' => null]);
    }

    public function test_cancelled_plans_and_already_suspended_schools_are_left_alone(): void
    {
        $cancelled = $this->school('cancelled', '2026-09-01');
        $byHand = $this->school('expired', '2026-09-01');
        $byHand->forceFill(['status' => Tenant::STATUS_SUSPENDED])->save();

        $this->tick();

        $this->assertSame(Tenant::STATUS_ACTIVE, $cancelled->fresh()->status);
        $this->assertNull(School::for($byHand)->billing_suspended_at);
    }

    public function test_a_payment_lifts_a_billing_suspension(): void
    {
        $school = $this->school('expired', '2026-10-01');
        $this->tick();
        $this->assertSame(Tenant::STATUS_SUSPENDED, $school->fresh()->status);

        $this->actingAsStaff($this->platformStaff());
        $receipt = $this->postJson("/api/v1/platform/schools/{$school->id}/billing/payments", ['periods' => 1, 'method' => 'cash'])
            ->assertCreated()->json('data.receipt_number');

        $this->assertSame(Tenant::STATUS_ACTIVE, $school->fresh()->status);
        $this->assertNull(School::for($school)->billing_suspended_at);
        $this->assertDatabaseHas('bo_school_status_history', ['tenant_id' => $school->id, 'to_status' => 'active', 'reason' => "Payment received ({$receipt})."]);
    }

    public function test_a_payment_never_lifts_a_suspension_staff_made(): void
    {
        $school = $this->school('active', '2026-11-30');
        $this->actingAsStaff($this->platformStaff());
        $this->postJson("/api/v1/platform/schools/{$school->id}/suspend", ['reason' => 'Abuse report'])->assertOk();

        $this->postJson("/api/v1/platform/schools/{$school->id}/billing/payments", ['periods' => 1, 'method' => 'cash'])->assertCreated();

        $this->assertSame(Tenant::STATUS_SUSPENDED, $school->fresh()->status);
    }

    public function test_moving_the_end_date_of_an_expired_plan_reactivates_it_and_lifts_the_suspension(): void
    {
        $school = $this->school('expired', '2026-10-01');
        $this->tick();
        $this->actingAsStaff($this->platformStaff());

        $this->patchJson("/api/v1/platform/schools/{$school->id}/billing", ['end_on' => '2026-10-31'])
            ->assertOk()
            ->assertJsonPath('data.subscription.status', 'active');

        $this->assertSame(Tenant::STATUS_ACTIVE, $school->fresh()->status);
    }

    public function test_a_school_staff_let_back_in_is_not_suspended_again_for_the_same_period(): void
    {
        $school = $this->school('expired', '2026-10-01');
        $this->tick();
        $this->actingAsStaff($this->platformStaff());
        $this->postJson("/api/v1/platform/schools/{$school->id}/reactivate", ['reason' => 'Promised to pay Friday'])->assertOk();

        $this->tick();

        $this->assertSame(Tenant::STATUS_ACTIVE, $school->fresh()->status);
    }

    public function test_the_owner_sees_a_banner_when_the_plan_is_ending_or_ended(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $sub = SchoolSubscription::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan()->id, 'status' => 'active',
            'start_on' => '2026-10-01', 'end_on' => '2026-10-28',
        ]);

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.billing_notice.plan.kind', 'ending')
            ->assertJsonPath('meta.tenant.billing_notice.plan.days_left', 10);

        $sub->update(['status' => 'expired', 'end_on' => '2026-10-15']);
        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.billing_notice.plan.kind', 'ended')
            ->assertJsonPath('meta.tenant.billing_notice.plan.suspend_on', '2026-10-23');

        $sub->update(['status' => 'active', 'end_on' => '2027-10-15']);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('meta.tenant.billing_notice', null);
    }

    public function test_the_banner_warns_near_the_student_limit_and_only_owners_see_it(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::TENANT_SETTINGS_UPDATE]);
        $this->setUpAcademicCatalog();
        SchoolSubscription::query()->create([
            'tenant_id' => $this->tenant->id, 'plan_id' => $this->plan(['max_students' => 10])->id, 'status' => 'active',
            'start_on' => '2026-10-01', 'end_on' => '2027-10-01',
        ]);
        Student::factory()->count(9)->create()->each(fn ($s) => Enrollment::factory()->forStudent($s)->forClass($this->computerEveningClass)->create());

        $this->getJson('/api/v1/auth/me')->assertOk()
            ->assertJsonPath('meta.tenant.billing_notice.students.active', 9)
            ->assertJsonPath('meta.tenant.billing_notice.students.limit', 10)
            ->assertJsonPath('meta.tenant.billing_notice.students.full', false);

        $this->actingAsAdminWithPermissions([Permissions::STUDENTS_VIEW]);
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('meta.tenant.billing_notice', null);
    }
}
