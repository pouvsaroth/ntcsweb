<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\DiscountCode;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\UserNotification;
use App\Support\Authorization\Permissions;
use App\Support\Notifications\NotificationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Invoices > Discount codes > Students to add discount: completed courses waiting for a code. */
class DiscountFollowUpTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [Permissions::DISCOUNT_CODES_VIEW, Permissions::DISCOUNT_CODES_UPDATE];

    public function test_a_course_turning_completed_is_listed_and_tells_who_can_give_codes(): void
    {
        $admin = $this->actingAsAdminWithPermissions(self::ALL);
        $enrollment = Enrollment::factory()->create(['start_study_on' => '2026-08-01']);

        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);

        $enrollment->refresh();
        $this->assertSame(Enrollment::FOLLOW_UP_WAITING, $enrollment->discount_follow_up);
        $this->assertSame(now()->toDateString(), $enrollment->completed_on?->toDateString());
        $this->assertTrue(UserNotification::query()->where('recipient_id', $admin->id)->where('type', NotificationType::STUDENT_COMPLETED_ADD_DISCOUNT)->exists());

        $this->getJson('/api/v1/discount-codes/follow-ups')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.rows.0.enrollment_id', $enrollment->id)
            ->assertJsonPath('data.rows.0.start_study_on', '2026-08-01')
            ->assertJsonPath('data.rows.0.completed_on', now()->toDateString());
    }

    public function test_a_course_created_completed_or_completed_before_is_not_listed(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        Enrollment::factory()->create(['status' => Enrollment::STATUS_COMPLETED]);

        $this->getJson('/api/v1/discount-codes/follow-ups')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_a_course_reopened_before_anyone_acted_leaves_the_list(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $enrollment = Enrollment::factory()->create();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $enrollment->update(['status' => Enrollment::STATUS_ACTIVE]);

        $this->assertNull($enrollment->fresh()->discount_follow_up);
        $this->getJson('/api/v1/discount-codes/follow-ups')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_saving_gives_each_student_their_own_code_with_its_expiry(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $student = Student::factory()->create(['student_code' => 'NTS700']);
        $enrollment = Enrollment::factory()->forStudent($student)->create();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $other = Enrollment::factory()->create();
        $other->update(['status' => Enrollment::STATUS_COMPLETED]);
        $main = DiscountCode::factory()->create(['code' => 'DONE10', 'starts_on' => now()->subDay()->toDateString(), 'expires_on' => null]);
        $day = now('Asia/Phnom_Penh')->format('dmy');
        $expires = now()->addMonth()->toDateString();

        $this->postJson('/api/v1/discount-codes/follow-ups', ['rows' => [
            ['enrollment_id' => $enrollment->id, 'discount_code_id' => $main->id, 'expires_on' => $expires],
        ]])->assertOk()
            ->assertJsonPath('data.given', 1)
            ->assertJsonPath('data.codes.0', "NTS700_DONE10_{$day}");

        $own = DiscountCode::query()->where('code', "NTS700_DONE10_{$day}")->sole();
        $this->assertSame($expires, $own->expires_on?->toDateString());
        $this->assertSame(Enrollment::FOLLOW_UP_GIVEN, $enrollment->fresh()->discount_follow_up);
        // The row not sent stays waiting.
        $this->getJson('/api/v1/discount-codes/follow-ups')->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.rows.0.enrollment_id', $other->id);
    }

    public function test_an_expiry_before_the_code_starts_gives_nothing(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $enrollment = Enrollment::factory()->create();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);
        $main = DiscountCode::factory()->create(['starts_on' => now()->addWeek()->toDateString(), 'expires_on' => null]);

        $this->postJson('/api/v1/discount-codes/follow-ups', ['rows' => [
            ['enrollment_id' => $enrollment->id, 'discount_code_id' => $main->id, 'expires_on' => now()->toDateString()],
        ]])->assertUnprocessable()->assertJsonValidationErrors('expires_on');

        $this->assertSame(Enrollment::FOLLOW_UP_WAITING, $enrollment->fresh()->discount_follow_up);
        $this->assertSame(0, DiscountCode::query()->whereNotNull('parent_id')->count());
    }

    public function test_skip_takes_a_student_off_the_list_without_a_code(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $enrollment = Enrollment::factory()->create();
        $enrollment->update(['status' => Enrollment::STATUS_COMPLETED]);

        $this->postJson('/api/v1/discount-codes/follow-ups/skip', ['enrollment_ids' => [$enrollment->id]])
            ->assertOk()->assertJsonPath('data.skipped', 1);

        $this->assertSame(Enrollment::FOLLOW_UP_SKIPPED, $enrollment->fresh()->discount_follow_up);
        $this->getJson('/api/v1/discount-codes/follow-ups')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_the_list_needs_the_update_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::DISCOUNT_CODES_VIEW]);

        $this->getJson('/api/v1/discount-codes/follow-ups')->assertForbidden();
        $this->postJson('/api/v1/discount-codes/follow-ups/skip', ['enrollment_ids' => [1]])->assertForbidden();
    }
}
