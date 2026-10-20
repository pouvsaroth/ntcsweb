<?php

declare(strict_types=1);

namespace App\Platform\Console;

use App\Platform\Models\Signup;
use App\Platform\Signup\Bakong;
use App\Platform\Signup\Signups;
use Illuminate\Console\Command;

/**
 * Every minute (docs/backoffice/stage-8-signup.md, 8c): asks Bakong about
 * unpaid website sign-ups — so a payment is never missed when the visitor
 * closed the page — and closes the ones past their hold.
 */
final class SignupsTickCommand extends Command
{
    protected $signature = 'platform:signups-tick';

    protected $description = 'Look up KHQR payments for website sign-ups and expire unpaid ones';

    public function handle(Signups $signups, Bakong $bakong): int
    {
        $late = (int) config('platform.bakong.late_minutes', 10);
        $paid = 0;

        if ($bakong->configured()) {
            Signup::query()->where('status', Signup::PENDING_PAYMENT)->whereNotNull('khqr_md5')
                ->where('expires_at', '>', now()->subMinutes($late))
                ->orderBy('id')->limit(200)->get()
                ->each(function (Signup $s) use ($signups, &$paid) {
                    try {
                        $paid += $signups->checkPayment($s)->status !== Signup::PENDING_PAYMENT ? 1 : 0;
                    } catch (\RuntimeException $e) {
                        report($e);

                        return false; // Bakong is not answering: stop for this minute.
                    }
                });
        }

        $expired = $signups->expireStale();
        $this->info("Sign-ups: {$paid} paid, {$expired} expired.");

        return self::SUCCESS;
    }
}
