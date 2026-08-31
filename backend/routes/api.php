<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AboutPageController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BookController;
use App\Http\Controllers\Api\V1\Admin\ClassroomController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentController;
use App\Http\Controllers\Api\V1\Admin\GalleryController as AdminGalleryController;
use App\Http\Controllers\Api\V1\Admin\GeneralSettingsController;
use App\Http\Controllers\Api\V1\Admin\HomeSlideController as AdminHomeSlideController;
use App\Http\Controllers\Api\V1\Admin\PositionController;
use App\Http\Controllers\Api\V1\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SchoolClassController;
use App\Http\Controllers\Api\V1\Admin\SchoolSettingsController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\StudentImportController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\Public\EnrollmentInquiryController;
use App\Http\Controllers\Api\V1\Public\GalleryController as PublicGalleryController;
use App\Http\Controllers\Api\V1\Public\HomeSlideController as PublicHomeSlideController;
use App\Http\Controllers\Api\V1\Public\ProgramController as PublicProgramController;
use App\Http\Controllers\Api\V1\Public\ScheduleController as PublicScheduleController;
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
            Route::post('me', [AuthController::class, 'updateProfile'])->name('me.update');
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

        // Bulk CSV import from the legacy `t_student` system — a separate
        // resource from `students` itself since an import is a background
        // job with its own lifecycle (pending/processing/completed/failed),
        // not a student record. index/store/show only: an import is never
        // updated or deleted once uploaded.
        Route::apiResource('student-imports', StudentImportController::class)
            ->only(['index', 'store', 'show']);
        Route::apiResource('classrooms', ClassroomController::class);
        Route::apiResource('books', BookController::class);

        // Named {class}, not {schoolClass}: matches the SchoolClass model's
        // $class parameter in each controller method and keeps the URL
        // ("/api/v1/classes/{class}") reading naturally despite the model
        // itself being named SchoolClass to dodge PHP's `class` keyword.
        Route::apiResource('classes', SchoolClassController::class)->parameters(['classes' => 'class']);

        Route::apiResource('enrollments', EnrollmentController::class);

        Route::apiResource('home-slides', AdminHomeSlideController::class);
        Route::apiResource('gallery', AdminGalleryController::class);
        Route::apiResource('programs', AdminProgramController::class);

        // Position-based automatic user roles: Staff belongs to a Position,
        // a Position carries a Role — see UserProvisioningService and
        // StaffController::store()/update() for how the three tie together.
        Route::apiResource('positions', PositionController::class);
        Route::apiResource('staff', StaffController::class);
        Route::apiResource('roles', RoleController::class)->except(['show']);

        // index/store only for now: editing/removing an existing account is a
        // separate, bigger "user management" surface not yet built. `store`
        // is how an already-imported (never auto-provisioned) Student gets
        // portal access, or how an extra standalone account gets created.
        Route::apiResource('users', UserController::class)->only(['index', 'store']);

        // Read-only — see AuditLogPolicy/AuditLogController's docblocks for
        // why there is deliberately no store/update/destroy route here.
        Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);

        // Singleton, not a resource — see AboutPageController.
        Route::get('settings/about', [AboutPageController::class, 'show'])->name('settings.about.show');
        Route::post('settings/about', [AboutPageController::class, 'update'])->name('settings.about.update');

        // Same shape — see GeneralSettingsController. Currently just
        // `student_id_prefix`; a later general setting adds a key here
        // rather than a new endpoint.
        Route::get('settings/general', [GeneralSettingsController::class, 'show'])->name('settings.general.show');
        Route::post('settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');

        // Same shape — see SchoolSettingsController. Writes straight to the
        // tenants table's own name/email/phone/address/logo columns; this is
        // what the public site's header/footer branding reads back.
        Route::get('settings/school', [SchoolSettingsController::class, 'show'])->name('settings.school.show');
        Route::post('settings/school', [SchoolSettingsController::class, 'update'])->name('settings.school.update');

        // Cambodia's administrative hierarchy — platform-wide reference data
        // for the student registration form's cascading address selects.
        Route::prefix('geo')->name('geo.')->group(function () {
            Route::get('provinces', [GeographyController::class, 'provinces'])->name('provinces');
            Route::get('districts', [GeographyController::class, 'districts'])->name('districts');
            Route::get('communes', [GeographyController::class, 'communes'])->name('communes');
            Route::get('villages', [GeographyController::class, 'villages'])->name('villages');
            Route::get('lookup', [GeographyController::class, 'lookup'])->name('lookup');
        });
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
        Route::get('gallery', [PublicGalleryController::class, 'index'])->name('gallery.index');
        Route::get('gallery/{id}/download', [PublicGalleryController::class, 'download'])
            ->whereNumber('id')
            ->name('gallery.download');
        Route::get('programs', [PublicProgramController::class, 'index'])->name('programs.index');
        Route::get('schedules', [PublicScheduleController::class, 'index'])->name('schedules.index');
        Route::post('enrollment-inquiries', [EnrollmentInquiryController::class, 'store'])->name('enrollment-inquiries.store');
    });
});
