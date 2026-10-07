<?php

declare(strict_types=1);

namespace Tests\Feature\Performance;

use App\Models\JobGrade;
use App\Models\Permission;
use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Models\Position;
use App\Models\PromotionRecommendation;
use App\Models\Role;
use App\Models\Staff;
use App\Models\StaffSalary;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Performance Management > Promotion recommendation (stage 3) —
 * recommending, approving, and applying it to the staff record and Payroll.
 */
class PromotionRecommendationTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_MANAGE, Permissions::PERFORMANCE_APPROVE_PROMOTION];

    private User $hr;

    private User $staffUser;

    private Staff $staff;

    private Position $senior;

    private JobGrade $grade;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hr = $this->actingAsAdminWithPermissions(self::ALL);
        $this->staffUser = User::factory()->forTenant($this->tenant)->create();
        $this->staff = Staff::factory()->withUser($this->staffUser)->create();
        $this->senior = Position::factory()->create(['name' => 'Senior teacher']);
        $this->grade = JobGrade::factory()->create(['name' => 'G5']);
        StaffSalary::factory()->create([
            'staff_id' => $this->staff->id, 'basic_salary' => 500, 'currency' => Tenant::CURRENCY_USD,
            'effective_from' => now()->subYear()->toDateString(), 'bank_name' => 'ABA', 'bank_account_number' => '000111',
        ]);
    }

    private function recommend(array $overrides = []): array
    {
        return $this->postJson('/api/v1/promotion-recommendations', [
            'staff_id' => $this->staff->id,
            'to_position_id' => $this->senior->id,
            'to_job_grade_id' => $this->grade->id,
            'new_basic_salary' => 650,
            'effective_date' => now()->toDateString(),
            'reason' => 'Top score two years running',
            ...$overrides,
        ])->assertCreated()->json('data');
    }

    public function test_a_recommendation_needs_a_change_and_keeps_what_they_have_now(): void
    {
        $this->postJson('/api/v1/promotion-recommendations', ['staff_id' => $this->staff->id, 'effective_date' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['to_position_id']);

        $recommendation = $this->recommend();

        $this->assertSame('pending', $recommendation['status']);
        $this->assertEquals(500, $recommendation['from_basic_salary']);
        $this->assertSame('USD', $recommendation['salary_currency']);
        $this->assertSame($this->staff->position_id, $recommendation['from_position']['id'] ?? null);
        // Anyone who can approve is told.
        $this->assertSame(1, UserNotification::where('recipient_id', $this->hr->id)->where('type', NotificationType::PROMOTION_SUBMITTED)->count());

        // One open recommendation at a time.
        $this->postJson('/api/v1/promotion-recommendations', ['staff_id' => $this->staff->id, 'new_basic_salary' => 700, 'effective_date' => now()->toDateString(), 'reason' => 'Again'])
            ->assertUnprocessable()->assertJsonValidationErrors(['staff_id']);
    }

    public function test_approving_one_that_is_due_applies_it_to_the_staff_record_and_payroll(): void
    {
        $recommendation = $this->recommend();

        $this->postJson("/api/v1/promotion-recommendations/{$recommendation['id']}/approve")->assertOk()->assertJsonPath('data.status', 'applied');

        $staff = $this->staff->fresh();
        $this->assertSame($this->senior->id, $staff->position_id);
        $this->assertSame($this->grade->id, $staff->job_grade_id);

        $salary = StaffSalary::query()->where('staff_id', $this->staff->id)->effectiveOn(now()->toDateString())->firstOrFail();
        $this->assertEquals(650, $salary->basic_salary);
        $this->assertSame('USD', $salary->currency);
        $this->assertSame('ABA', $salary->bank_name);
        $this->assertSame(1, UserNotification::where('recipient_id', $this->staffUser->id)->where('type', NotificationType::PROMOTION_APPLIED)->count());
    }

    public function test_one_dated_ahead_waits_and_is_applied_when_its_day_comes(): void
    {
        $recommendation = $this->recommend(['effective_date' => now()->addMonth()->startOfMonth()->toDateString()]);
        $this->postJson("/api/v1/promotion-recommendations/{$recommendation['id']}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertNotSame($this->senior->id, $this->staff->fresh()->position_id);

        $this->travelTo(now()->addMonth()->startOfMonth()->addHour());
        $this->artisan('performance:apply-promotions')->assertSuccessful();

        $this->assertSame('applied', PromotionRecommendation::query()->findOrFail($recommendation['id'])->status);
        $this->assertSame($this->senior->id, $this->staff->fresh()->position_id);
    }

    public function test_rejecting_needs_a_reason_and_deciding_needs_the_approve_permission(): void
    {
        $recommendation = $this->recommend();

        $clerk = User::factory()->forTenant($this->tenant)->create();
        $role = Role::factory()->forTenant($this->tenant)->create(['slug' => 'hr-clerk', 'level' => 50]);
        $role->permissions()->attach(Permission::query()->whereIn('slug', [Permissions::PERFORMANCE_VIEW, Permissions::PERFORMANCE_MANAGE])->pluck('id'));
        $clerk->attachRoles($role);
        $this->actingAsTenantUser($clerk);
        $this->postJson("/api/v1/promotion-recommendations/{$recommendation['id']}/approve")->assertForbidden();

        $this->actingAsTenantUser($this->hr);
        $this->postJson("/api/v1/promotion-recommendations/{$recommendation['id']}/reject", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/promotion-recommendations/{$recommendation['id']}/reject", ['reason' => 'Next year'])
            ->assertOk()->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.decision_reason', 'Next year');

        // Rejected — a new one can be made.
        $this->recommend();
    }

    public function test_a_new_salary_needs_a_current_one_to_follow(): void
    {
        $other = Staff::factory()->create();

        $this->postJson('/api/v1/promotion-recommendations', ['staff_id' => $other->id, 'new_basic_salary' => 600, 'effective_date' => now()->toDateString(), 'reason' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['new_basic_salary']);
    }

    public function test_suggestions_are_a_cycles_top_scorers_without_an_open_recommendation(): void
    {
        $cycle = PerformanceCycle::factory()->create();
        $other = Staff::factory()->create();
        $low = Staff::factory()->create();
        PerformanceReview::query()->create(['performance_cycle_id' => $cycle->id, 'staff_id' => $this->staff->id, 'status' => 'completed', 'final_score' => 4.6]);
        PerformanceReview::query()->create(['performance_cycle_id' => $cycle->id, 'staff_id' => $other->id, 'status' => 'completed', 'final_score' => 4.1]);
        PerformanceReview::query()->create(['performance_cycle_id' => $cycle->id, 'staff_id' => $low->id, 'status' => 'completed', 'final_score' => 3.2]);

        $this->recommend();

        $ids = collect($this->getJson("/api/v1/promotion-recommendations/suggestions?performance_cycle_id={$cycle->id}")->assertOk()->json('data'))->pluck('staff.id')->all();
        $this->assertSame([$other->id], $ids);

        $this->getJson("/api/v1/promotion-recommendations/current/{$this->staff->id}")->assertOk()->assertJsonPath('data.basic_salary', 500)->assertJsonPath('data.latest_review.final_score', 4.6);
    }
}
