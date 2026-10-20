<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\AuditLogController;
use App\Platform\Http\Controllers\BillingSettingsController;
use App\Platform\Http\Controllers\DataOpsController;
use App\Platform\Http\Controllers\PaymentController;
use App\Platform\Http\Controllers\PlanController;
use App\Platform\Http\Controllers\SignupController;
use App\Platform\Http\Controllers\RoleController;
use App\Platform\Http\Controllers\SchoolBillingController;
use App\Platform\Http\Controllers\SchoolController;
use App\Platform\Http\Controllers\SchoolDomainController;
use App\Platform\Http\Controllers\SchoolMoveController;
use App\Platform\Http\Controllers\SchoolOwnerController;
use App\Platform\Http\Controllers\SecuritySettingsController;
use App\Platform\Http\Controllers\StaffAuthController;
use App\Platform\Http\Controllers\StaffController;
use App\Platform\Http\Controllers\SupportController;
use App\Platform\Http\Controllers\Website\WebsiteAboutController;
use App\Platform\Http\Controllers\Website\WebsiteDownloadsController;
use App\Platform\Http\Controllers\Website\WebsiteImagesController;
use App\Platform\Http\Controllers\Website\WebsiteServicesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The back office — /api/v1/platform/*
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php under the `web` middleware group (session
| cookie + CSRF), NOT the `api` group: ResolveTenant and
| EnsureTenantMatchesUser never run here, because staff are not inside a
| school. Every route past sign-in names a platform.* permission —
| PlatformRoutesTest fails for one that does not.
|
*/

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('login', [StaffAuthController::class, 'login'])->name('login');
    Route::post('two-factor', [StaffAuthController::class, 'twoFactor'])->name('two-factor');
    Route::post('two-factor/setup', [StaffAuthController::class, 'setupTwoFactor'])->name('two-factor.setup');
    Route::post('two-factor/confirm', [StaffAuthController::class, 'confirmTwoFactor'])->name('two-factor.confirm');

    // Signed in, but no permission needed: these are about your own account,
    // and the password route is the way out of PASSWORD_CHANGE_REQUIRED.
    Route::middleware('platform.staff')->group(function () {
        Route::get('session', [StaffAuthController::class, 'session'])->name('session');
        Route::post('logout', [StaffAuthController::class, 'logout'])->name('logout');
        Route::put('password', [StaffAuthController::class, 'password'])->name('password');
        Route::post('recovery-codes', [StaffAuthController::class, 'recoveryCodes'])->name('recovery-codes');
    });
});

Route::middleware('platform.staff')->group(function () {
    Route::get('staff', [StaffController::class, 'index'])->middleware('platform.can:platform.staff.read')->name('staff.index');
    Route::get('staff/{staff}', [StaffController::class, 'show'])->middleware('platform.can:platform.staff.read')->name('staff.show');
    Route::post('staff', [StaffController::class, 'store'])->middleware('platform.can:platform.staff.write')->name('staff.store');
    Route::patch('staff/{staff}', [StaffController::class, 'update'])->middleware('platform.can:platform.staff.write')->name('staff.update');
    Route::post('staff/{staff}/reset-two-factor', [StaffController::class, 'resetTwoFactor'])->middleware('platform.can:platform.staff.write')->name('staff.reset-two-factor');
    Route::post('staff/{staff}/reset-password', [StaffController::class, 'resetPassword'])->middleware('platform.can:platform.staff.write')->name('staff.reset-password');

    Route::get('permissions', [RoleController::class, 'permissions'])->middleware('platform.can:platform.role.read')->name('permissions');
    Route::get('roles', [RoleController::class, 'index'])->middleware('platform.can:platform.role.read')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('platform.can:platform.role.write')->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('platform.can:platform.role.write')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('platform.can:platform.role.write')->name('roles.destroy');
    Route::post('roles/{role}/copy', [RoleController::class, 'copy'])->middleware('platform.can:platform.role.write')->name('roles.copy');

    Route::get('schools', [SchoolController::class, 'index'])->middleware('platform.can:platform.school.read')->name('schools.index');
    Route::get('schools/{school}', [SchoolController::class, 'show'])->middleware('platform.can:platform.school.read')->name('schools.show');
    Route::post('schools/test-connection', [SchoolController::class, 'testConnection'])->middleware('platform.can:platform.school.write')->name('schools.test-connection');
    Route::post('schools', [SchoolController::class, 'store'])->middleware('platform.can:platform.school.write')->name('schools.store');
    Route::patch('schools/{school}', [SchoolController::class, 'update'])->middleware('platform.can:platform.school.write')->name('schools.update');
    Route::post('schools/{school}/retry', [SchoolController::class, 'retry'])->middleware('platform.can:platform.school.write')->name('schools.retry');
    Route::post('schools/{school}/suspend', [SchoolController::class, 'suspend'])->middleware('platform.can:platform.school.status')->name('schools.suspend');
    Route::post('schools/{school}/reactivate', [SchoolController::class, 'reactivate'])->middleware('platform.can:platform.school.status')->name('schools.reactivate');
    Route::post('schools/{school}/archive', [SchoolController::class, 'archive'])->middleware('platform.can:platform.school.status')->name('schools.archive');

    // Moving a school's data between placements (stage 4d).
    Route::get('schools/{school}/moves', [SchoolMoveController::class, 'index'])->middleware('platform.can:platform.school.read')->name('schools.moves.index');
    Route::post('schools/{school}/moves', [SchoolMoveController::class, 'store'])->middleware('platform.can:platform.school.placement')->name('schools.moves.store');
    Route::post('school-moves/{move}/remove-old-copy', [SchoolMoveController::class, 'removeOldCopy'])->middleware('platform.can:platform.school.placement')->name('school-moves.remove-old-copy');

    Route::post('schools/{school}/owner/invite', [SchoolOwnerController::class, 'resendInvite'])->middleware('platform.can:platform.school.write')->name('schools.owner.invite');
    Route::post('schools/{school}/owner/invite-link', [SchoolOwnerController::class, 'inviteLink'])->middleware('platform.can:platform.school.write')->name('schools.owner.invite-link');
    Route::put('schools/{school}/owner', [SchoolOwnerController::class, 'change'])->middleware('platform.can:platform.school.write')->name('schools.owner.change');

    Route::post('schools/{school}/domains', [SchoolDomainController::class, 'store'])->middleware('platform.can:platform.school.write')->name('schools.domains.store');
    Route::post('schools/{school}/domains/{domain}/verify', [SchoolDomainController::class, 'verify'])->middleware('platform.can:platform.school.write')->name('schools.domains.verify');
    Route::post('schools/{school}/domains/{domain}/primary', [SchoolDomainController::class, 'primary'])->middleware('platform.can:platform.school.write')->name('schools.domains.primary');
    Route::delete('schools/{school}/domains/{domain}', [SchoolDomainController::class, 'destroy'])->middleware('platform.can:platform.school.write')->name('schools.domains.destroy');

    Route::prefix('data')->name('data.')->group(function () {
        Route::get('databases', [DataOpsController::class, 'databases'])->middleware('platform.can:platform.data.read')->name('databases');
        Route::get('health', [DataOpsController::class, 'health'])->middleware('platform.can:platform.data.read')->name('health');
        Route::get('backups', [DataOpsController::class, 'backups'])->middleware('platform.can:platform.data.read')->name('backups.index');
        Route::post('backups', [DataOpsController::class, 'backupNow'])->middleware('platform.can:platform.data.backup')->name('backups.store');
        Route::get('backups/{backup}/download', [DataOpsController::class, 'download'])->middleware('platform.can:platform.data.backup')->name('backups.download');
        Route::delete('backups/{backup}', [DataOpsController::class, 'destroyBackup'])->middleware('platform.can:platform.data.backup')->name('backups.destroy');
        Route::get('backup-schedule', [DataOpsController::class, 'schedule'])->middleware('platform.can:platform.data.read')->name('schedule.show');
        Route::put('backup-schedule', [DataOpsController::class, 'updateSchedule'])->middleware('platform.can:platform.data.backup')->name('schedule.update');
        Route::get('migration-runs', [DataOpsController::class, 'migrationRuns'])->middleware('platform.can:platform.data.read')->name('migrations.index');
        Route::post('migrate', [DataOpsController::class, 'migrate'])->middleware('platform.can:platform.data.migrate')->name('migrate');
        Route::post('schools/{school}/create-database', [DataOpsController::class, 'createDatabase'])->middleware('platform.can:platform.data.migrate')->name('create-database');
    });

    // Billing (stage 5): plans, each school's subscription, settings.
    Route::prefix('billing')->name('billing.')->group(function () {
        Route::get('plans', [PlanController::class, 'index'])->middleware('platform.can:platform.billing.read')->name('plans.index');
        Route::post('plans', [PlanController::class, 'store'])->middleware('platform.can:platform.billing.manage')->name('plans.store');
        Route::patch('plans/{plan}', [PlanController::class, 'update'])->middleware('platform.can:platform.billing.manage')->name('plans.update');
        Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->middleware('platform.can:platform.billing.manage')->name('plans.destroy');
        Route::get('settings', [BillingSettingsController::class, 'show'])->middleware('platform.can:platform.billing.read')->name('settings.show');
        Route::put('settings', [BillingSettingsController::class, 'update'])->middleware('platform.can:platform.billing.manage')->name('settings.update');
        Route::post('settings/logo', [BillingSettingsController::class, 'uploadLogo'])->middleware('platform.can:platform.billing.manage')->name('settings.logo.store');
        Route::delete('settings/logo', [BillingSettingsController::class, 'removeLogo'])->middleware('platform.can:platform.billing.manage')->name('settings.logo.destroy');
        Route::get('payments', [PaymentController::class, 'index'])->middleware('platform.can:platform.billing.read')->name('payments.index');
        // Self-service sign-ups from the website (stage 8).
        Route::get('signups', [SignupController::class, 'index'])->middleware('platform.can:platform.billing.read')->name('signups.index');
        Route::post('signups/{signup}/retry', [SignupController::class, 'retry'])->middleware('platform.can:platform.billing.manage')->name('signups.retry');
        Route::put('settings/payment', [BillingSettingsController::class, 'updatePayment'])->middleware('platform.can:platform.billing.manage')->name('settings.payment.update');
        Route::post('settings/payment/test', [BillingSettingsController::class, 'testBakong'])->middleware('platform.can:platform.billing.manage')->name('settings.payment.test');
        Route::post('payments/{payment}/void', [PaymentController::class, 'void'])->middleware('platform.can:platform.billing.manage')->name('payments.void');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->middleware('platform.can:platform.billing.read')->name('payments.receipt');
    });
    Route::get('schools/{school}/billing', [SchoolBillingController::class, 'show'])->middleware('platform.can:platform.billing.read')->name('schools.billing.show');
    Route::post('schools/{school}/billing/subscribe', [SchoolBillingController::class, 'subscribe'])->middleware('platform.can:platform.billing.manage')->name('schools.billing.subscribe');
    Route::post('schools/{school}/billing/change-plan', [SchoolBillingController::class, 'changePlan'])->middleware('platform.can:platform.billing.manage')->name('schools.billing.change-plan');
    Route::patch('schools/{school}/billing', [SchoolBillingController::class, 'update'])->middleware('platform.can:platform.billing.manage')->name('schools.billing.update');
    Route::get('schools/{school}/billing/payment-quote', [PaymentController::class, 'quote'])->middleware('platform.can:platform.billing.read')->name('schools.billing.payment-quote');
    Route::post('schools/{school}/billing/payments', [PaymentController::class, 'store'])->middleware('platform.can:platform.billing.manage')->name('schools.billing.payments.store');
    Route::post('schools/{school}/billing/cancel', [SchoolBillingController::class, 'cancel'])->middleware('platform.can:platform.billing.manage')->name('schools.billing.cancel');

    Route::get('schools/{school}/health', [SupportController::class, 'health'])->middleware('platform.can:platform.support.read')->name('schools.health');
    Route::get('schools/{school}/support/users', [SupportController::class, 'users'])->middleware('platform.can:platform.support.view')->name('schools.support.users');
    Route::post('schools/{school}/support/sessions', [SupportController::class, 'store'])->middleware('platform.can:platform.support.view')->name('schools.support.store');
    Route::get('support/sessions', [SupportController::class, 'index'])->middleware('platform.can:platform.support.read')->name('support.index');
    Route::get('support/sessions/{session}', [SupportController::class, 'show'])->middleware('platform.can:platform.support.read')->name('support.show');
    Route::post('support/sessions/{session}/end', [SupportController::class, 'end'])->middleware('platform.can:platform.support.view')->name('support.end');
    Route::get('support/settings', [SupportController::class, 'settings'])->middleware('platform.can:platform.support.read')->name('support.settings');
    Route::patch('support/settings', [SupportController::class, 'updateSettings'])->middleware('platform.can:platform.support.view')->name('support.settings.update');

    // Website: NewTech's public website (docs/backoffice/stage-7-website.md).
    // The permission first, then step into the school (EnterWebsiteSchool).
    Route::prefix('website')->name('website.')->group(function () {
        $read = ['platform.can:platform.website.read', 'platform.website'];
        $manage = ['platform.can:platform.website.manage', 'platform.website'];

        Route::get('/', [WebsiteAboutController::class, 'summary'])->middleware($read)->name('summary');
        Route::get('about', [WebsiteAboutController::class, 'show'])->middleware($read)->name('about');
        Route::post('about', [WebsiteAboutController::class, 'update'])->middleware($manage)->name('about.update');
        Route::get('services', [WebsiteServicesController::class, 'show'])->middleware($read)->name('services');
        Route::put('services/settings', [WebsiteServicesController::class, 'updateSettings'])->middleware($manage)->name('services.settings');
        Route::post('services/cards', [WebsiteServicesController::class, 'storeCard'])->middleware($manage)->name('services.cards.store');
        Route::put('services/cards/{id}', [WebsiteServicesController::class, 'updateCard'])->where('id', '[A-Za-z0-9-]+')->middleware($manage)->name('services.cards.update');
        Route::delete('services/cards/{id}', [WebsiteServicesController::class, 'destroyCard'])->where('id', '[A-Za-z0-9-]+')->middleware($manage)->name('services.cards.destroy');

        Route::get('download-folders', [WebsiteDownloadsController::class, 'folders'])->middleware($read)->name('download-folders.index');
        Route::post('download-folders', [WebsiteDownloadsController::class, 'storeFolder'])->middleware($manage)->name('download-folders.store');
        Route::patch('download-folders/{id}', [WebsiteDownloadsController::class, 'updateFolder'])->whereNumber('id')->middleware($manage)->name('download-folders.update');
        Route::delete('download-folders/{id}', [WebsiteDownloadsController::class, 'destroyFolder'])->whereNumber('id')->middleware($manage)->name('download-folders.destroy');
        Route::get('download-folders/{id}/files', [WebsiteDownloadsController::class, 'files'])->whereNumber('id')->middleware($read)->name('download-files.index');
        Route::post('download-folders/{id}/files', [WebsiteDownloadsController::class, 'storeFile'])->whereNumber('id')->middleware($manage)->name('download-files.store');
        Route::patch('download-files/{id}', [WebsiteDownloadsController::class, 'updateFile'])->whereNumber('id')->middleware($manage)->name('download-files.update');
        Route::delete('download-files/{id}', [WebsiteDownloadsController::class, 'destroyFile'])->whereNumber('id')->middleware($manage)->name('download-files.destroy');

        // Home slides, gallery, promotions: one shape each (WebsiteImagesController).
        foreach (['slides', 'gallery', 'promotions'] as $kind) {
            Route::get($kind, [WebsiteImagesController::class, 'index'])->defaults('kind', $kind)->middleware($read)->name("{$kind}.index");
            Route::post($kind, [WebsiteImagesController::class, 'store'])->defaults('kind', $kind)->middleware($manage)->name("{$kind}.store");
            Route::get("{$kind}/{id}", [WebsiteImagesController::class, 'show'])->defaults('kind', $kind)->whereNumber('id')->middleware($read)->name("{$kind}.show");
            Route::put("{$kind}/{id}", [WebsiteImagesController::class, 'update'])->defaults('kind', $kind)->whereNumber('id')->middleware($manage)->name("{$kind}.update");
            Route::delete("{$kind}/{id}", [WebsiteImagesController::class, 'destroy'])->defaults('kind', $kind)->whereNumber('id')->middleware($manage)->name("{$kind}.destroy");
        }
    });

    Route::get('settings/security', [SecuritySettingsController::class, 'show'])->middleware('platform.can:platform.security.manage')->name('settings.security');
    Route::put('settings/security', [SecuritySettingsController::class, 'update'])->middleware('platform.can:platform.security.manage')->name('settings.security.update');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('platform.can:platform.audit.read')->name('audit-logs.index');
});
