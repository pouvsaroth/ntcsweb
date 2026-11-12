<?php

declare(strict_types=1);

namespace Tests\Feature\Marketing;

use App\Models\DiscountCode;
use App\Models\Inquiry;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\Student;
use App\Support\Authorization\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasAcademicAdmin;
use Tests\TestCase;

/** Marketing > Referrals: "Referred by" on students and inquiries; the friend's first payment gives the referrer a reward code. */
class ReferralTest extends TestCase
{
    use HasAcademicAdmin, RefreshDatabase;

    private const ALL = [
        Permissions::REFERRALS_VIEW, Permissions::REFERRALS_UPDATE,
        Permissions::INQUIRIES_VIEW, Permissions::INQUIRIES_CREATE, Permissions::INQUIRIES_UPDATE,
    ];

    private DiscountCode $reward;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(12, 0));
    }

    private function turnOn(?int $days = 30): void
    {
        $this->reward = DiscountCode::factory()->create(['code' => 'FRIEND', 'name' => 'Bring a friend', 'value' => 10]);
        $this->putJson('/api/v1/referrals/settings', ['enabled' => true, 'reward_discount_code_id' => $this->reward->id, 'valid_days' => $days])
            ->assertOk()->assertJsonPath('data.enabled', true);
    }

    public function test_the_friends_first_payment_gives_the_referrer_their_own_code(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $this->turnOn(30);
        $referrer = Student::factory()->create();
        $friend = Student::factory()->create(['referred_by_student_id' => $referrer->id]);

        $referral = Referral::query()->where('referred_student_id', $friend->id)->firstOrFail();
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);

        Payment::factory()->create(['student_id' => $friend->id]);
        $referral->refresh();
        $this->assertSame(Referral::STATUS_REWARDED, $referral->status);
        $code = DiscountCode::query()->findOrFail($referral->reward_discount_code_id);
        $this->assertSame($this->reward->id, $code->parent_id);
        $this->assertSame('2026-12-12', $code->expires_on->toDateString());
        $this->assertTrue($code->students()->whereKey($referrer->id)->exists());

        // A second payment gives nothing more; a second friend gives a second code.
        Payment::factory()->create(['student_id' => $friend->id]);
        $this->assertSame(1, DiscountCode::query()->where('parent_id', $this->reward->id)->count());
        $other = Student::factory()->create(['referred_by_student_id' => $referrer->id]);
        Payment::factory()->create(['student_id' => $other->id]);
        $this->assertSame(2, DiscountCode::query()->where('parent_id', $this->reward->id)->count());

        $this->getJson('/api/v1/referrals')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'rewarded');
        $this->getJson('/api/v1/referrals/summary')->assertOk()->assertJsonPath('data.rewarded', 2)->assertJsonPath('data.referrers', 1);
    }

    public function test_when_referrals_are_off_it_stays_pending_and_staff_can_give_it_by_hand(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $referrer = Student::factory()->create();
        $friend = Student::factory()->create(['referred_by_student_id' => $referrer->id]);
        Payment::factory()->create(['student_id' => $friend->id]);

        $referral = Referral::query()->firstOrFail();
        $this->assertSame(Referral::STATUS_PENDING, $referral->status);
        $this->postJson("/api/v1/referrals/{$referral->id}/give")->assertUnprocessable();

        $this->turnOn();
        $this->putJson('/api/v1/referrals/settings', ['enabled' => false, 'reward_discount_code_id' => $this->reward->id, 'valid_days' => null])->assertOk();
        $this->postJson("/api/v1/referrals/{$referral->id}/give")->assertOk()->assertJsonPath('data.status', 'rewarded');
        $this->postJson("/api/v1/referrals/{$referral->id}/give")->assertUnprocessable();
        // No days: the main code's own end date.
        $this->assertSame($this->reward->expires_on->toDateString(), $referral->fresh()->rewardCode->expires_on->toDateString());
    }

    public function test_changing_or_clearing_referred_by_follows_until_rewarded(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        [$a, $b] = Student::factory()->count(2)->create();
        $friend = Student::factory()->create(['referred_by_student_id' => $a->id]);

        $friend->update(['referred_by_student_id' => $b->id]);
        $this->assertSame($b->id, Referral::query()->value('referrer_student_id'));
        $friend->update(['referred_by_student_id' => null]);
        $this->assertSame(0, Referral::query()->count());
    }

    public function test_an_inquiry_referred_by_carries_over_when_registered(): void
    {
        $this->actingAsAdminWithPermissions(self::ALL);
        $referrer = Student::factory()->create();
        $inquiry = $this->postJson('/api/v1/inquiries', [
            'inquiry_date' => '2026-11-10', 'name' => 'Dara', 'phone' => '012', 'source' => 'referral', 'referred_by_student_id' => $referrer->id,
        ])->assertCreated()->assertJsonPath('data.referred_by.id', $referrer->id)->json('data');

        $new = Student::factory()->create();
        $this->postJson("/api/v1/inquiries/{$inquiry['id']}/enroll", ['student_id' => $new->id])->assertOk();

        $this->assertSame($referrer->id, $new->fresh()->referred_by_student_id);
        $this->assertSame($referrer->id, Referral::query()->where('referred_student_id', $new->id)->value('referrer_student_id'));
        $this->assertSame(1, Inquiry::query()->count());
    }

    public function test_the_student_search_and_settings_need_permission(): void
    {
        $this->actingAsAdminWithPermissions([Permissions::INQUIRIES_VIEW]);
        Student::factory()->create(['first_name' => 'Sophea']);
        $this->getJson('/api/v1/referrals/student-options?search=soph')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/referrals')->assertForbidden();

        $this->actingAsAdminWithPermissions([Permissions::REFERRALS_VIEW]);
        $this->getJson('/api/v1/referrals/settings')->assertOk()->assertJsonPath('data.enabled', false);
        $this->putJson('/api/v1/referrals/settings', ['enabled' => false])->assertForbidden();
        $this->assertFalse(ReferralSetting::current()->enabled);
    }
}
