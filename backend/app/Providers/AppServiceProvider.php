<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
use App\Support\Tenancy\TenantUrl;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->configureModels();
        $this->configurePasswords();
        $this->configureEmailVerification();
        $this->configureDatabaseGuards();
        $this->configureRateLimiting();
    }

    private function configureModels(): void
    {
        // Accessing an attribute that was never selected, or a relation that
        // was never eager loaded, becomes an error instead of a silent extra
        // query. This is what keeps N+1 problems out of the codebase as the
        // record counts grow.
        Model::shouldBeStrict(! app()->isProduction());

        // Mass assignment of an undeclared attribute is always a bug, including
        // in production, where it is how tenant_id would get overwritten.
        Model::preventSilentlyDiscardingAttributes();
    }

    private function configurePasswords(): void
    {
        Password::defaults(function () {
            return app()->isProduction()
                ? Password::min(10)->letters()->mixedCase()->numbers()->uncompromised()
                : Password::min(8)->letters()->numbers();
        });
    }

    private function configureEmailVerification(): void
    {
        // The signed link has to land on the SPA, not on an API endpoint, and
        // on the right school's front end.
        VerifyEmail::createUrlUsing(function (object $notifiable) {
            $signed = URL::temporarySignedRoute(
                'api.v1.auth.verify-email',
                now()->addMinutes((int) config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ],
                absolute: false,
            );

            return app(TenantUrl::class)->frontend(
                $notifiable->tenant ?? null,
                '/verify-email',
                ['redirect' => $signed, 'tenant' => $notifiable->tenant?->slug],
            );
        });
    }

    private function configureDatabaseGuards(): void
    {
        if (app()->isProduction()) {
            return;
        }

        // Surfaces slow queries in development while the dataset is still small
        // enough to fix them cheaply.
        DB::whenQueryingForLongerThan(500, function ($connection, $event) {
            logger()->warning('Slow query', [
                'sql' => $event->sql,
                'time_ms' => $event->time,
            ]);
        });
    }

    private function configureRateLimiting(): void
    {
        // Coarse per-IP-and-tenant limit ahead of LoginRequest's own
        // per-account throttle: this one stops an IP grinding through many
        // different email addresses at one school.
        RateLimiter::for('auth', function (Request $request) {
            $tenantId = app(TenantContext::class)->id() ?? 'platform';

            return Limit::perMinute(20)->by("auth:{$tenantId}:{$request->ip()}");
        });

        RateLimiter::for('verification', function (Request $request) {
            return Limit::perMinutes(5, 6)->by(
                $request->user()?->getKey() ?? $request->ip()
            );
        });

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by(
                $request->user()?->getKey() ?? $request->ip()
            );
        });
    }
}
