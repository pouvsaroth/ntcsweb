<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Needs a scheduler running (`php artisan schedule:work`, or cron calling
// `schedule:run` every minute) — see the `scheduler` service in docker-compose.
Schedule::command('students:deactivate-idle')->timezone('Asia/Phnom_Penh')->dailyAt('01:00')->withoutOverlapping();
Schedule::command('staff:sync-login-access')->timezone('Asia/Phnom_Penh')->dailyAt('01:05')->withoutOverlapping();
Schedule::command('performance:apply-promotions')->timezone('Asia/Phnom_Penh')->dailyAt('01:10')->withoutOverlapping();

// The back office (docs/backoffice/stage-3-data-ops.md): scheduler heartbeat,
// scheduled backups when due, and retention. Cheap when there is nothing to do.
Schedule::command('platform:backups-tick')->everyMinute()->withoutOverlapping();

// Billing (docs/backoffice/stage-5-billing.md, 5e): expire ended plans, then
// suspend schools unpaid past the grace days. Just after midnight, Phnom Penh.
// Website sign-ups (stage 8c): look up KHQR payments the visitor's page did not wait for.
Schedule::command('platform:signups-tick')->everyMinute()->withoutOverlapping();
// Website demos (stage 12): switch off demo accounts past their days.
Schedule::command('platform:demos-tick')->hourly()->withoutOverlapping();
// Notification groups (stage 11): link Telegram groups from "/link CODE" messages.
Schedule::command('platform:telegram-tick')->everyMinute()->withoutOverlapping();
Schedule::command('platform:billing-tick')->timezone('Asia/Phnom_Penh')->dailyAt('00:15')->withoutOverlapping();
