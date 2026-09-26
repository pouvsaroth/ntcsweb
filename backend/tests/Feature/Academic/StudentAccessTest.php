<?php

declare(strict_types=1);

namespace Tests\Feature\Academic;

use App\Models\Enrollment;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

class StudentAccessTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    /** @return array{0: User, 1: Enrollment} */
    private function studyingStudent(): array
    {
        $this->actingAsAdminWithPermissions([]);

        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);
        $enrollment = Enrollment::factory()->forStudent($student)->create(['status' => Enrollment::STATUS_ACTIVE]);

        return [$user, $enrollment];
    }

    private function login(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('X-Tenant', $this->tenant->slug)->postJson('/api/v1/auth/login', [
            'login' => $user->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ]);
    }

    public function test_leaving_studying_starts_the_countdown_and_the_account_goes_inactive_after_it(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $this->assertNull($user->fresh()->studying_ended_at);

        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $this->assertNotNull($user->fresh()->studying_ended_at);

        $this->travel(14)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);

        $this->travel(2)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);

        $this->login($user->fresh())->assertUnprocessable()->assertJsonValidationErrors('login');
    }

    public function test_login_refuses_an_expired_student_even_before_the_daily_run(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $enrollment->update(['status' => Enrollment::STATUS_ABANDONED]);

        $this->travel(16)->days();

        $this->login($user->fresh())->assertUnprocessable()->assertJsonValidationErrors('login');
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);
    }

    public function test_an_existing_session_is_cut_off_once_the_grace_period_ends(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $enrollment->update(['status' => Enrollment::STATUS_STOPPED]);

        $this->travel(16)->days();

        $this->actingAsTenantUser($user->fresh())->getJson('/api/v1/auth/me')->assertForbidden();
    }

    public function test_the_grace_period_follows_the_school_setting(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $this->tenant->update(['settings' => ['student_inactive_after_days' => 30]]);
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);

        $this->travel(20)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);

        $this->travel(11)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);
    }

    public function test_another_studying_enrollment_keeps_the_account_active(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        Enrollment::factory()->forStudent($enrollment->student)->create(['status' => Enrollment::STATUS_ACTIVE]);

        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);

        $this->assertNull($user->fresh()->studying_ended_at);
    }

    public function test_studying_again_reactivates_an_auto_deactivated_account(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $this->travel(16)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);

        // A new course, not Studying yet — still locked out.
        $next = Enrollment::factory()->forStudent($enrollment->student)->create(['status' => Enrollment::STATUS_NOT_STARTED]);
        $this->assertSame(User::STATUS_INACTIVE, $user->fresh()->status);

        $next->update(['status' => Enrollment::STATUS_ACTIVE]);

        $fresh = $user->fresh();
        $this->assertSame(User::STATUS_ACTIVE, $fresh->status);
        $this->assertNull($fresh->studying_ended_at);
        $this->assertNull($fresh->auto_deactivated_at);
        $this->login($fresh)->assertOk();
    }

    public function test_an_admin_suspension_is_never_lifted_automatically(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $enrollment->update(['status' => Enrollment::STATUS_ACTIVE]);

        $this->assertSame(User::STATUS_SUSPENDED, $user->fresh()->status);
    }

    public function test_an_admin_reactivating_an_auto_deactivated_account_is_not_undone_by_the_next_run(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $this->travel(16)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();

        $user->fresh()->forceFill(['status' => User::STATUS_ACTIVE])->save();

        $this->travel(1)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_the_daily_run_starts_the_countdown_for_students_who_stopped_before_this_existed(): void
    {
        [$user, $enrollment] = $this->studyingStudent();
        // Simulates a row that changed status without the model hook (e.g. legacy data).
        Enrollment::query()->whereKey($enrollment->id)->toBase()->update(['status' => Enrollment::STATUS_COMPLETED]);
        $this->assertNull($user->fresh()->studying_ended_at);

        $this->artisan('students:deactivate-idle')->assertSuccessful();
        $this->assertNotNull($user->fresh()->studying_ended_at);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }

    public function test_a_student_who_has_never_studied_has_no_countdown(): void
    {
        $this->actingAsAdminWithPermissions([]);
        $user = User::factory()->forTenant($this->tenant)->create();
        $student = Student::factory()->create(['user_id' => $user->id]);
        Enrollment::factory()->forStudent($student)->create(['status' => Enrollment::STATUS_NOT_STARTED]);

        $this->travel(30)->days();
        $this->artisan('students:deactivate-idle')->assertSuccessful();

        $this->assertNull($user->fresh()->studying_ended_at);
        $this->assertSame(User::STATUS_ACTIVE, $user->fresh()->status);
    }
}
