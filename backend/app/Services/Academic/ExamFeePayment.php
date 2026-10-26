<?php

declare(strict_types=1);

namespace App\Services\Academic;

use App\Models\ExamApplication;
use App\Platform\Signup\Bakong;
use App\Support\Billing\Khqr;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * The exam fee paid by QR (docs/academics/exam-fee-payment.md): a one-time
 * KHQR from the school's own code with the fee amount, and Bakong asked
 * whether it was paid — the same way a plan sign-up is (App\Platform\Signup\Signups).
 */
final class ExamFeePayment
{
    /** How long one QR code is valid. */
    public const VALID_MINUTES = 15;

    /** Bakong is asked at most this often per application. */
    public const CHECK_EVERY_SECONDS = 5;

    public function __construct(
        private readonly TenantContext $context,
        private readonly Bakong $bakong,
    ) {}

    /** Can this school take the exam fee by QR? (It has set its KHQR code.) */
    public function qrAvailable(): bool
    {
        return filled($this->context->getOrFail()->khqrTemplate());
    }

    /**
     * The QR to pay this application's fee: the one already made while it is
     * still valid for a minute or more, else a new one.
     *
     * @return array{khqr: string, expires_at: string, amount: float, currency: string}
     */
    public function qrFor(ExamApplication $application): array
    {
        if ($application->paid_at !== null) {
            throw ValidationException::withMessages(['payment' => __('This exam fee is already paid.')]);
        }
        if (! in_array($application->status, [ExamApplication::STATUS_PENDING, ExamApplication::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['payment' => __('This application can no longer be paid online.')]);
        }
        $amount = (float) $application->fee_amount;
        $currency = $application->fee_currency ?: 'USD';
        $template = $this->context->getOrFail()->khqrTemplate();
        if (blank($template) || $amount <= 0) {
            throw ValidationException::withMessages(['payment' => __('This school has not set up QR payment yet. Please pay by cash.')]);
        }

        if ($application->khqr === null || $application->khqr_expires_at === null || $application->khqr_expires_at->copy()->subMinute()->isPast()) {
            $now = now();
            $expires = $now->copy()->addMinutes(self::VALID_MINUTES);
            try {
                $code = Khqr::forPayment($template, $amount, $currency, $now, $expires);
            } catch (InvalidArgumentException $e) {
                report($e);
                throw ValidationException::withMessages(['payment' => __('QR payment is misconfigured for this school. Please pay by cash.')]);
            }
            $application->forceFill([
                'payment_method' => 'qr',
                'khqr' => $code,
                'khqr_md5' => md5($code),
                'khqr_expires_at' => $expires,
                'khqr_checked_at' => null,
            ])->save();
        }

        return [
            'khqr' => (string) $application->khqr,
            'expires_at' => $application->khqr_expires_at->toIso8601String(),
            'amount' => $amount,
            'currency' => $currency,
        ];
    }

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
