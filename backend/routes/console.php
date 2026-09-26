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
