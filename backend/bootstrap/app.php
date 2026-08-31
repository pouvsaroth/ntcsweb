<?php

use App\Exceptions\Tenancy\TenantMismatchException;
use App\Exceptions\Tenancy\TenantNotResolvedException;
use App\Http\Middleware\EnsureTenantMatchesUser;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\RequireTenant;
use App\Http\Middleware\ResolveTenant;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            // Turns a request from a first-party SPA on a stateful domain into
            // a session-authenticated one (cookie + CSRF), while leaving
            // everything else to authenticate by Bearer token. Must run before
            // tenant resolution so the authenticated user is available to the
            // resolver chain.
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            // Every API request gets a tenant context...
            ResolveTenant::class,
            // ...and no authenticated user may act outside their own school.
            EnsureTenantMatchesUser::class,
        ]);

        // Laravel's default middlewarePriority list runs SubstituteBindings
        // right after auth, regardless of where these two were registered
        // above — and since ResolveTenant isn't in that list at all, it was
        // simply left running *after* SubstituteBindings. That's a real bug,
        // not a theoretical one: SubstituteBindings is what queries a
        // tenant-scoped model for implicit route-model binding (every
        // show/update/destroy route), so on any live request that reaches
        // authentication lazily (a Bearer token, not a pre-seeded test
        // session), that query ran with no tenant in context yet and threw.
        // Anchoring on the *interface* Authenticate implements, because
        // that's the literal string Laravel's default priority array
        // contains — anchoring on the concrete Authenticate class would
        // silently no-op here since `in_array` requires an exact match.
        $middleware->appendToPriorityList(
            after: \Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            append: ResolveTenant::class,
        );

        $middleware->alias([
            'tenant.required' => RequireTenant::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // Tenant hostnames are dynamic, so the trusted-host list cannot be
        // enumerated up front; the proxy in front of the app is responsible for
        // rejecting unknown Host headers.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Cross-tenant access is security-relevant and must always be visible
        // in the logs, with the detail the client never sees.
        $exceptions->report(function (TenantMismatchException $e) {
            logger()->warning('Cross-tenant access refused', $e->context());

            return false;
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => ApiResponse::validationError($e->errors(), $e->getMessage()),

                $e instanceof AuthenticationException => ApiResponse::error(
                    __('Unauthenticated.'), 401, 'UNAUTHENTICATED',
                ),

                $e instanceof AuthorizationException => ApiResponse::error(
                    $e->getMessage() ?: __('This action is unauthorized.'), 403, 'FORBIDDEN',
                ),

                // Answering 404 with the model name would confirm that a record
                // exists in another school, so the message stays generic.
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error(
                    __('Resource not found.'), 404, 'NOT_FOUND',
                ),

                $e instanceof TooManyRequestsHttpException => ApiResponse::error(
                    __('Too many requests. Please slow down.'), 429, 'RATE_LIMITED',
                ),

                // A missing tenant is a server-side bug (or a probe), never
                // something to explain to the caller.
                $e instanceof TenantNotResolvedException => ApiResponse::error(
                    __('Resource not found.'), 404, 'TENANT_NOT_RESOLVED',
                ),

                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    $e->getMessage() ?: __('Request failed.'),
                    $e->getStatusCode(),
                ),

                default => null, // Let Laravel handle it: debug detail locally, generic 500 in production.
            };
        });
    })
    ->create();
