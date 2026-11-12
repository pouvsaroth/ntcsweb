<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\DiscountCode;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\ReferralSetting;
use App\Models\Student;
use App\Models\User;
use App\Services\Billing\DiscountCodeService;
use App\Services\Notifications\NotificationService;
use App\Support\Notifications\NotificationType;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Marketing > Referrals. A student's "Referred by" keeps one referral row in
 * step (sync); the friend's first completed payment gives the referrer their
 * own copy of the reward discount code (rewardForPayment), or staff give it
 * by hand (give).
 */
final class ReferralService
{
    public function __construct(
        private readonly DiscountCodeService $discountCodes,
        private readonly NotificationService $notifications,
    ) {}

    /** The student's "Referred by" was set, changed or cleared. A rewarded referral is kept as it is. */
    public function sync(Student $student): void
    {
        $referral = Referral::query()->where('referred_student_id', $student->id)->first();
        if ($referral?->status === Referral::STATUS_REWARDED) {
            return;
        }

        $referrerId = $student->referred_by_student_id;
        if ($referrerId === null || (int) $referrerId === (int) $student->id) {
            $referral?->delete();

            return;
        }

        if ($referral === null) {
            Referral::query()->create(['referrer_student_id' => $referrerId, 'referred_student_id' => $student->id]);
        } elseif ((int) $referral->referrer_student_id !== (int) $referrerId) {
            $referral->update(['referrer_student_id' => $referrerId]);
        }
    }

    /**
     * A payment was recorded: the friend's pending referral is rewarded when
     * referrals are on. Never stops the payment — a failure is only reported.
     */
    public function rewardForPayment(Payment $payment): void
    {
        if ($payment->student_id === null || ! $payment->isCounted()) {
            return;
        }
        $referral = Referral::query()->where('referred_student_id', $payment->student_id)->where('status', Referral::STATUS_PENDING)->first();
        if ($referral === null) {
            return;
        }
        $settings = ReferralSetting::current();
        if (! $settings->enabled || $this->usableMain($settings) === null) {
            return;
        }

        try {
            // A savepoint: if the reward fails, the payment around it still saves.
            DB::connection('tenant')->transaction(fn () => $this->reward($referral, $settings, $payment, auth()->user()));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Staff give the reward now (e.g. referrals were off at the first payment). */
    public function give(Referral $referral, ?User $actor): Referral
    {
        if ($referral->status !== Referral::STATUS_PENDING) {
            throw ValidationException::withMessages(['referral' => __('This referral already has its reward.')]);
        }
        $settings = ReferralSetting::current();
        if ($this->usableMain($settings) === null) {
            throw ValidationException::withMessages(['referral' => __('Pick an active reward discount code in the referral settings first.')]);
        }

        return DB::connection('tenant')->transaction(fn () => $this->reward($referral, $settings, null, $actor));
    }

    private function usableMain(ReferralSetting $settings): ?DiscountCode
    {
        $main = $settings->reward_discount_code_id ? DiscountCode::query()->find($settings->reward_discount_code_id) : null;

        return $main !== null && $main->parent_id === null && $main->status === DiscountCode::STATUS_ACTIVE ? $main : null;
    }

    private function reward(Referral $referral, ReferralSetting $settings, ?Payment $payment, ?User $actor): Referral
    {
        $referral = Referral::query()->lockForUpdate()->findOrFail($referral->id);
        if ($referral->status !== Referral::STATUS_PENDING) {
            return $referral;
        }
        $main = $this->usableMain($settings);
        $referrer = Student::query()->find($referral->referrer_student_id);
        if ($main === null || $referrer === null) {
            return $referral;
        }

        $today = Carbon::parse($this->discountCodes->today());
        $expires = $settings->valid_days ? $today->copy()->addDays($settings->valid_days)->toDateString() : $main->expires_on?->toDateString();
        $code = DiscountCode::query()->create([
            ...$main->only(DiscountCodeService::SHARED),
            'starts_on' => $main->starts_on && $main->starts_on->toDateString() > $today->toDateString() ? $main->starts_on->toDateString() : $today->toDateString(),
            'expires_on' => $expires,
            'parent_id' => $main->id,
            // One code per referral, so a student who brings two friends gets two.
            'code' => $this->discountCodes->ownCodeFor($main, $referrer, $today),
            'audience' => DiscountCode::AUDIENCE_PERSONAL,
            'notes' => trim(($main->notes ? $main->notes.' · ' : '').__('Referral reward')),
            'created_by' => $actor?->id,
        ]);
        $code->students()->attach($referrer->id);

        $referral->update([
            'status' => Referral::STATUS_REWARDED,
            'payment_id' => $payment?->id,
            'reward_discount_code_id' => $code->id,
            'rewarded_on' => $today->toDateString(),
        ]);

        $this->notify($code, $referrer);

        return $referral;
    }

    /** Same message as Discount codes > Assign: the referrer's own code. */
    private function notify(DiscountCode $code, Student $referrer): void
    {
        if ($referrer->user_id === null || $code->status !== DiscountCode::STATUS_ACTIVE) {
            return;
        }
        $user = User::query()->find($referrer->user_id);
        if ($user === null) {
            return;
        }
        $discount = $code->discount_type === DiscountCode::TYPE_PERCENT
            ? rtrim(rtrim(number_format((float) $code->value, 2), '0'), '.').'%'
            : number_format((float) $code->value, $code->currency === 'KHR' ? 0 : 2).' '.$code->currency;

        $this->notifications->notifyMany([$user], NotificationType::DISCOUNT_CODE_GIVEN, [
            'code' => $code->code,
            'name' => $code->name,
            'discount' => $discount,
            'expires_on' => $code->expires_on?->format('d-m-Y') ?? '—',
        ], link: '/admin/my-discount-codes');
    }
}
