<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\BookController;
use App\Http\Controllers\Api\V1\Admin\ClassroomController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentController;
use App\Http\Controllers\Api\V1\Admin\HomeSlideController as AdminHomeSlideController;
use App\Http\Controllers\Api\V1\Admin\SchoolClassController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Public\HomeSlideController as PublicHomeSlideController;
use App\Http\Controllers\Api\V1\Public\SiteSettingsController;
use App\Http\Controllers\Api\V1\TenantDirectoryController;
use App\Http\Responses\ApiResponse;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Everything is versioned under /api/v1. A future v2 gets its own file and
| its own controller namespace rather than breaking existing clients.
|
| Tenant resolution runs for every route in the group (registered in
| bootstrap/app.php), including the unauthenticated ones — the public school
| website is tenant-scoped too. `auth:sanctum` accepts either a session cookie
| from the first-party SPA or a Bearer token.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function () {

    Route::get('/', fn () => ApiResponse::success([
        'name' => config('app.name'),
        'version' => 'v1',
    ]))->name('index');

    /*
    |----------------------------------------------------------------------
    | Tenant directory
    |----------------------------------------------------------------------
    |
    | Unauthenticated and outside the tenant-scoped groups on purpose — this
    | is how a central domain (no subdomain, e.g. local development) offers a
    | school picker on the login screen. `throttle:api` keeps it from being
    | scraped wholesale.
    |
    */

    Route::middleware('throttle:api')->group(function () {
        Route::get('tenants', [TenantDirectoryController::class, 'index'])->name('tenants.index');
    });

    /*
    |----------------------------------------------------------------------
    | Authentication
    |----------------------------------------------------------------------
    */

    Route::prefix('auth')->name('auth.')->group(function () {

        // Throttled per IP on top of the per-account throttle inside
        // LoginRequest, so one address cannot grind through many accounts.
        Route::middleware('throttle:auth')->group(function () {
            Route::post('login', [AuthController::class, 'login'])->name('login');
            Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('forgot-password');
            Route::post('reset-password', [PasswordController::class, 'reset'])->name('reset-password');
        });

        // Unauthenticated by design: the signature in the link is the
        // credential, and it expires on its own.
        Route::get('verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['signed', 'throttle:verification'])
            ->name('verify-email');

        Route::middleware(['auth:sanctum', 'active'])->group(function () {
            Route::get('me', [AuthController::class, 'me'])->name('me');
            Route::post('logout', [AuthController::class, 'logout'])->name('logout');
            Route::post('change-password', [PasswordController::class, 'change'])->name('change-password');

            Route::post('verify-email/resend', [EmailVerificationController::class, 'resend'])
                ->middleware('throttle:verification')
                ->name('verify-email.resend');
        });
    });

    /*
    |----------------------------------------------------------------------
    | Authenticated application
    |----------------------------------------------------------------------
    |
    | Resource routes land here as each phase is built. `active` re-checks the
    | account and school status on every request so a suspension takes effect
    | immediately rather than at next sign-in.
    |
    */

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        // Every route below is implicitly tenant-scoped: Teacher, Student,
        // Classroom, Book, SchoolClass, and Enrollment all use BelongsToTenant,
        // so both the index queries and the {param} route-model bindings only
        // ever see the current school's rows — a stray id for another
        // tenant's record 404s the same way a nonexistent id would.
        Route::apiResource('teachers', TeacherController::class);
        Route::apiResource('students', StudentController::class);
        Route::apiResource('classrooms', ClassroomController::class);
        Route::apiResource('books', BookController::class);

        // Named {class}, not {schoolClass}: matches the SchoolClass model's
        // $class parameter in each controller method and keeps the URL
        // ("/api/v1/classes/{class}") reading naturally despite the model
        // itself being named SchoolClass to dodge PHP's `class` keyword.
        Route::apiResource('classes', SchoolClassController::class)->parameters(['classes' => 'class']);

        Route::apiResource('enrollments', EnrollmentController::class);

        Route::apiResource('home-slides', AdminHomeSlideController::class);
    });

    /*
    |----------------------------------------------------------------------
    | Public school website
    |----------------------------------------------------------------------
    |
    | Unauthenticated, but still tenant-scoped: `tenant.required` refuses the
    | request unless a school was resolved from the hostname (or, in local
    | development, from the X-Tenant header).
    |
    */

    Route::prefix('public')->name('public.')->middleware('tenant.required')->group(function () {
        Route::get('settings', [SiteSettingsController::class, 'show'])->name('settings');
        Route::get('home-slides', [PublicHomeSlideController::class, 'index'])->name('home-slides.index');
    });
});
