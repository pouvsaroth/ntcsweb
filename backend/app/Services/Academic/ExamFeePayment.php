<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ExamApplication;
use App\Platform\Signup\Bakong;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Exam fees paid by QR (docs/academics/exam-fee-payment.md). New QR codes
 * are no longer made (the school KHQR was removed); this only lets Bakong
 * confirm a QR that was already shown before then.
 */
final class ExamFeePayment
{
    /** Bakong is asked at most this often per application. */
    public const CHECK_EVERY_SECONDS = 5;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Bakong $bakong,
    ) {}

    /**
     * Has the current QR been paid? Asks Bakong (at most every few seconds,
     * until a few minutes after the code expired); paid → stamps the payment.
     * Bakong being unreachable or not set up just leaves it unpaid.
     */
    public function check(ExamApplication $application): ExamApplication
    {
        $late = (int) config('platform.bakong.late_minutes', 10);
        if ($application->paid_at !== null || $application->khqr_md5 === null || $application->khqr_expires_at === null
            || $application->khqr_expires_at->copy()->addMinutes($late)->isPast() || ! $this->bakong->configured()) {
            return $application;
        }

        return DB::connection($application->getConnectionName())->transaction(function () use ($application) {
            /** @var ExamApplication $fresh */
            $fresh = ExamApplication::query()->lockForUpdate()->findOrFail($application->getKey());
            if ($fresh->paid_at !== null || $fresh->khqr_md5 === null
                || ($fresh->khqr_checked_at !== null && $fresh->khqr_checked_at->diffInSeconds(now()) < self::CHECK_EVERY_SECONDS)) {
                return $fresh;
            }
            $fresh->forceFill(['khqr_checked_at' => now()])->save();

            try {
                $paid = $this->bakong->paymentFor($fresh->khqr_md5);
            } catch (RuntimeException $e) {
                report($e);

                return $fresh;
            }
            if ($paid === null) {
                return $fresh;
            }

            // Paid — the amount and currency must be what was asked.
            $right = $paid['currency'] === ($fresh->fee_currency ?: 'USD') && $paid['amount'] + 0.001 >= (float) $fresh->fee_amount;
            if (! $right) {
                report(new RuntimeException("Exam application {$fresh->id}: paid {$paid['amount']} {$paid['currency']}, expected {$fresh->fee_amount} {$fresh->fee_currency}."));

                return $fresh;
            }
            $fresh->forceFill([
                'paid_at' => now(),
                'paid_amount' => $paid['amount'],
                'paid_currency' => $paid['currency'],
                'bakong_hash' => $paid['hash'],
            ])->save();

            return $fresh;
        });
    }

    /**
     * Checks the applications still waiting on a recent QR — for a list
     * being opened (the student's own, or the Approvals queue).
     *
     * @param  iterable<ExamApplication>  $applications
     */
    public function checkWaiting(iterable $applications, int $limit = 10): void
    {
        $late = (int) config('platform.bakong.late_minutes', 10);
        $asked = 0;
        foreach ($applications as $application) {
            if ($asked >= $limit) {
                return;
            }
            if ($application->paid_at === null && $application->khqr_md5 !== null && $application->khqr_expires_at !== null
                && ! $application->khqr_expires_at->copy()->addMinutes($late)->isPast()) {
                $fresh = $this->check($application);
                $application->setRawAttributes($fresh->getAttributes(), true);
                $asked++;
            }
        }
    }
}
