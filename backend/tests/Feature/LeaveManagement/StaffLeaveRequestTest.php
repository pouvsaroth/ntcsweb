<?php

declare(strict_types=1);

namespace Tests\Feature\LeaveManagement;

use App\Models\Holiday;
use App\Models\LeavePolicy;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Shift;
use App\Models\Staff;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Leave\LeaveBalanceService;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/**
 * HRM > Leave Management: a staff member's leave request — its leave type,
 * full or half day, working days, and the policy's limits and balance.
 * "Today" is Monday 5 October 2026; staff work Monday–Friday.
 */
class StaffLeaveRequestTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private User $user;

    private Staff $staff;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 09:00:00');

        $this->user = $this->actingAsAdminWithPermissions([]);
        $this->staff = Staff::factory()->create(['user_id' => $this->user->id, 'hire_date' => '2020-01-01', 'gender' => 'female']);

        $day = Shift::factory()->create();
        WorkSchedule::factory()->create([
            'is_default' => true,
            'monday_shift_id' => $day->id, 'tuesday_shift_id' => $day->id, 'wednesday_shift_id' => $day->id,
            'thursday_shift_id' => $day->id, 'friday_shift_id' => $day->id,
        ]);

        $this->annual = LeaveType::factory()->create(['code' => 'AL', 'name' => 'Annual leave']);
        LeavePolicy::factory()->create(['leave_type_id' => $this->annual->id, 'days_per_year' => 10, 'prorate_first_year' => false]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function ask(array $data): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/my-leave-requests', [
            'leave_type_id' => $this->annual->id,
            'reason' => 'Family trip',
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_a_request_counts_working_days_skipping_weekends_and_holidays(): void
    {
        Holiday::query()->create(['name' => 'Pchum Ben', 'start_date' => '2026-10-15', 'end_date' => '2026-10-15']);

        // Mon 12 – Sun 18 October: five weekdays, one a holiday.
        $this->ask(['from_date' => '2026-10-12', 'to_date' => '2026-10-18'])
            ->assertCreated()
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.day_part', 'full')
            ->assertJsonPath('data.leave_type.id', $this->annual->id);

        $this->getJson('/api/v1/my-leave-requests/quote?from_date=2026-10-12&to_date=2026-10-18')->assertOk()->assertJsonPath('data.days', 4);

        // Pending days already come off what's available.
        $this->getJson('/api/v1/my-leave-requests/types')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->annual->id)
            ->assertJsonPath('data.0.balance.entitlement', 10)
            ->assertJsonPath('data.0.balance.pending', 4)
            ->assertJsonPath('data.0.balance.available', 6);

        // Only a weekend: nothing to take leave from.
        $this->ask(['from_date' => '2026-10-24', 'to_date' => '2026-10-25'])->assertUnprocessable()->assertJsonValidationErrors(['from_date']);
    }

    public function test_half_days_are_half_a_day_and_the_two_halves_of_a_day_dont_clash(): void
    {
        $this->ask(['from_date' => '2026-10-07', 'to_date' => '2026-10-07', 'day_part' => 'morning'])->assertCreated()->assertJsonPath('data.days', 0.5);
        $this->ask(['from_date' => '2026-10-07', 'to_date' => '2026-10-07', 'day_part' => 'afternoon'])->assertCreated();
        $this->ask(['from_date' => '2026-10-07', 'to_date' => '2026-10-07', 'day_part' => 'morning'])->assertUnprocessable()->assertJsonValidationErrors(['from_date']);
        $this->ask(['from_date' => '2026-10-07', 'to_date' => '2026-10-07'])->assertUnprocessable()->assertJsonValidationErrors(['from_date']);

        // A half day is one date, and only for a type that allows it.
        $this->ask(['from_date' => '2026-10-08', 'to_date' => '2026-10-09', 'day_part' => 'morning'])->assertUnprocessable()->assertJsonValidationErrors(['day_part']);
        $this->annual->update(['allow_half_day' => false]);
        $this->ask(['from_date' => '2026-10-08', 'to_date' => '2026-10-08', 'day_part' => 'morning'])->assertUnprocessable()->assertJsonValidationErrors(['day_part']);
    }

    public function test_a_request_cannot_take_more_than_the_balance_left(): void
    {
        $this->ask(['from_date' => '2026-10-12', 'to_date' => '2026-10-23'])->assertCreated()->assertJsonPath('data.days', 10);
        $this->ask(['from_date' => '2026-11-02', 'to_date' => '2026-11-02', 'day_part' => 'morning'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['to_date']);

        // A rejected request gives its days back.
        LeaveRequest::query()->update(['status' => LeaveRequest::STATUS_REJECTED]);
        $this->ask(['from_date' => '2026-11-02', 'to_date' => '2026-11-02'])->assertCreated();
    }

    public function test_policy_limits_on_service_length_days_in_a_row_and_notice(): void
    {
        LeavePolicy::query()->update(['min_service_months' => 3, 'max_consecutive_days' => 3, 'min_notice_days' => 7]);

        $this->ask(['from_date' => '2026-10-19', 'to_date' => '2026-10-22'])->assertUnprocessable()->assertJsonValidationErrors(['to_date']);
        $this->ask(['from_date' => '2026-10-08', 'to_date' => '2026-10-08'])->assertUnprocessable()->assertJsonValidationErrors(['from_date']);
        $this->ask(['from_date' => '2026-10-19', 'to_date' => '2026-10-21'])->assertCreated();

        $this->staff->update(['hire_date' => '2026-09-01']);
        $this->ask(['from_date' => '2026-11-02', 'to_date' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors(['from_date']);
        $this->ask(['from_date' => '2026-12-01', 'to_date' => '2026-12-01'])->assertCreated();
    }

    public function test_the_type_must_suit_the_staff_member_and_may_need_a_file(): void
    {
        Storage::fake('public');
        $paternity = LeaveType::factory()->create(['gender' => 'male']);
        $sick = LeaveType::factory()->create(['requires_attachment' => true]);

        $this->ask(['leave_type_id' => $paternity->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-12'])->assertUnprocessable()->assertJsonValidationErrors(['leave_type_id']);
        $this->ask(['leave_type_id' => null, 'from_date' => '2026-10-12', 'to_date' => '2026-10-12'])->assertUnprocessable()->assertJsonValidationErrors(['leave_type_id']);

        $this->ask(['leave_type_id' => $sick->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-12'])->assertUnprocessable()->assertJsonValidationErrors(['attachments']);
        // No policy for sick leave: not limited by a balance.
        $this->ask([
            'leave_type_id' => $sick->id, 'from_date' => '2026-10-12', 'to_date' => '2026-10-30',
            'attachments' => [UploadedFile::fake()->image('certificate.jpg')],
        ])->assertCreated()->assertJsonPath('data.days', 15);

        $this->getJson('/api/v1/my-leave-requests/types')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_a_request_stays_within_one_year(): void
    {
        $this->ask(['from_date' => '2026-12-30', 'to_date' => '2027-01-04'])->assertUnprocessable()->assertJsonValidationErrors(['to_date']);
    }

    public function test_without_any_leave_types_a_staff_request_works_as_before(): void
    {
        LeavePolicy::query()->delete();
        LeaveType::query()->delete();

        $this->ask(['leave_type_id' => null, 'from_date' => '2026-10-12', 'to_date' => '2026-10-13'])
            ->assertCreated()
            ->assertJsonPath('data.leave_type', null)
            ->assertJsonPath('data.days', null);
    }

    public function test_entitlement_is_prorated_in_the_first_year_and_grows_with_service(): void
    {
        $service = app(LeaveBalanceService::class);
        $policy = new LeavePolicy(['days_per_year' => 18, 'prorate_first_year' => true, 'service_bonus_every_years' => 3, 'service_bonus_days' => 1, 'max_days_per_year' => 20]);

        $joinedJuly = new Staff(['hire_date' => '2026-07-01']);
        $this->assertSame(9.0, $service->entitlement($joinedJuly, $policy, 2026));
        $this->assertSame(18.0, $service->entitlement($joinedJuly, $policy, 2027));
        $this->assertSame(0.0, $service->entitlement($joinedJuly, $policy, 2025));

        $veteran = new Staff(['hire_date' => '2014-03-01']);
        // 12 years by the end of 2026 → +4 days, capped at 20.
        $this->assertSame(20.0, $service->entitlement($veteran, $policy, 2026));
        $sixYears = new Staff(['hire_date' => '2020-03-01']);
        $this->assertSame(20.0, $service->entitlement($sixYears, $policy, 2026));
        $fourYears = new Staff(['hire_date' => '2022-03-01']);
        $this->assertSame(19.0, $service->entitlement($fourYears, $policy, 2026));
    }

    public function test_hr_can_file_for_a_staff_member_without_the_notice_period(): void
    {
        LeavePolicy::query()->update(['min_notice_days' => 7]);
        $other = Staff::factory()->create(['hire_date' => '2020-01-01']);

        $this->postJson('/api/v1/leave-requests', ['staff_id' => $other->id, 'leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-05', 'reason' => 'Sick'])
            ->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_MANAGE, Permissions::LEAVE_REQUESTS_VIEW]);
        $this->postJson('/api/v1/leave-requests', ['staff_id' => $other->id, 'leave_type_id' => $this->annual->id, 'from_date' => '2026-10-05', 'to_date' => '2026-10-06', 'reason' => 'Sick'])
            ->assertCreated()
            ->assertJsonPath('data.staff.id', $other->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 2);

        $this->getJson('/api/v1/leave-requests?requester=staff&year=2026&filter[leave_type_id]='.$this->annual->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_leave_type_with_requests_cannot_be_deleted(): void
    {
        $this->ask(['from_date' => '2026-10-12', 'to_date' => '2026-10-12'])->assertCreated();
        LeavePolicy::query()->delete();

        $this->actingAsAdminWithPermissions([Permissions::LEAVE_MANAGEMENT_VIEW, Permissions::LEAVE_MANAGEMENT_MANAGE]);
        $this->deleteJson("/api/v1/leave-types/{$this->annual->id}")->assertUnprocessable();
    }
}
