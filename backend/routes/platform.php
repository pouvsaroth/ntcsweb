<?php

declare(strict_types=1);

use App\Platform\Http\Controllers\AuditLogController;
use App\Platform\Http\Controllers\DataOpsController;
use App\Platform\Http\Controllers\RoleController;
use App\Platform\Http\Controllers\SchoolController;
use App\Platform\Http\Controllers\SchoolDomainController;
use App\Platform\Http\Controllers\SchoolMoveController;
use App\Platform\Http\Controllers\SchoolOwnerController;
use App\Platform\Http\Controllers\StaffAuthController;
use App\Platform\Http\Controllers\StaffController;
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

    Route::get('audit-logs', [AuditLogController::class, 'index'])->middleware('platform.can:platform.audit.read')->name('audit-logs.index');
});
