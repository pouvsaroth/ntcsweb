<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AboutPageController;
use App\Http\Controllers\Api\V1\Admin\AccountController;
use App\Http\Controllers\Api\V1\Admin\AccountingDashboardController;
use App\Http\Controllers\Api\V1\Admin\AccountingPeriodController;
use App\Http\Controllers\Api\V1\Admin\AccountingReportController;
use App\Http\Controllers\Api\V1\Admin\AccountingSettingsController;
use App\Http\Controllers\Api\V1\Admin\AssetCategoryController;
use App\Http\Controllers\Api\V1\Admin\AssetController;
use App\Http\Controllers\Api\V1\Admin\AssetDashboardController;
use App\Http\Controllers\Api\V1\Admin\AssetDocumentController;
use App\Http\Controllers\Api\V1\Admin\AssetIssueController;
use App\Http\Controllers\Api\V1\Admin\AssetLocationController;
use App\Http\Controllers\Api\V1\Admin\AssetMaintenanceController;
use App\Http\Controllers\Api\V1\Admin\AssetRepairController;
use App\Http\Controllers\Api\V1\Admin\AssetReportController;
use App\Http\Controllers\Api\V1\Admin\AttendanceController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BillingDashboardController;
use App\Http\Controllers\Api\V1\Admin\BookController;
use App\Http\Controllers\Api\V1\Admin\ClassroomController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentController;
use App\Http\Controllers\Api\V1\Admin\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\FinancialTransactionController;
use App\Http\Controllers\Api\V1\Admin\GalleryController as AdminGalleryController;
use App\Http\Controllers\Api\V1\Admin\GeneralSettingsController;
use App\Http\Controllers\Api\V1\Admin\HomeSlideController as AdminHomeSlideController;
use App\Http\Controllers\Api\V1\Admin\IncomeController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\PaymentController;
use App\Http\Controllers\Api\V1\Admin\PositionController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\V1\Admin\RepairShopController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SchoolClassController;
use App\Http\Controllers\Api\V1\Admin\SchoolSettingsController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\StudentImportController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\MyAssetController;
use App\Http\Controllers\Api\V1\MyAttendanceController;
use App\Http\Controllers\Api\V1\MyInvoiceController;
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

        // Attendance — history/review list, plus per-class "take attendance"
        // (roster + save) nested under the class it belongs to. See
        // AttendanceService/AttendancePolicy/SchoolClassPolicy::recordAttendance().
        Route::apiResource('attendance', AttendanceController::class)->only(['index', 'show']);
        Route::get('classes/{class}/attendance', [AttendanceController::class, 'roster'])->name('classes.attendance.roster');
        Route::post('classes/{class}/attendance', [AttendanceController::class, 'store'])->name('classes.attendance.store');

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

        /*
        |------------------------------------------------------------------
        | Billing: Products, Invoices, Payments
        |------------------------------------------------------------------
        |
        | Generic catalog-based billing — see Product/Invoice/Payment model
        | docblocks. Access is gated per-route via the billing.* permissions
        | (ProductPolicy/InvoicePolicy/PaymentPolicy), not by being inside
        | this group — a Student account reaching these hits 403/404 the
        | same way any other unauthorized request would; students use the
        | separate `my-invoices` endpoints below instead.
        |
        */

        Route::apiResource('products', ProductController::class);
        Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->name('products.variants.store');
        Route::put('product-variants/{variant}', [ProductVariantController::class, 'update'])->name('product-variants.update');
        Route::delete('product-variants/{variant}', [ProductVariantController::class, 'destroy'])->name('product-variants.destroy');

        Route::apiResource('invoices', InvoiceController::class)->only(['index', 'store', 'show']);
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
        Route::post('invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('invoices.send');
        Route::get('invoices/{invoice}/notifications', [InvoiceController::class, 'notifications'])->name('invoices.notifications');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
        Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
        Route::post('payments/{payment}/refund', [PaymentController::class, 'refund'])->name('payments.refund');
        Route::get('payments/{payment}/receipt', [PaymentController::class, 'receipt'])->name('payments.receipt');

        Route::get('billing/dashboard', [BillingDashboardController::class, 'summary'])->name('billing.dashboard');
        Route::get('billing/reports/payments-by-method', [BillingDashboardController::class, 'paymentsByMethod'])->name('billing.reports.payments-by-method');

        /*
        |------------------------------------------------------------------
        | Accounting: Chart of Accounts, Income, Expenses, Ledger, Reports
        |------------------------------------------------------------------
        |
        | Sits on top of Billing rather than beside it — Payment -> Revenue
        | recognition happens automatically inside PaymentService (see
        | FinancialTransactionService), not through any route here. See
        | ChartOfAccountsSeeder for the default accounts every tenant starts
        | with, and AccountingSettingsController for where a school points
        | its Cash/Bank/Revenue defaults.
        |
        */

        Route::apiResource('accounts', AccountController::class)->except(['destroy']);
        Route::post('accounts/{account}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');
        Route::post('accounts/{account}/reactivate', [AccountController::class, 'reactivate'])->name('accounts.reactivate');

        Route::get('income', [IncomeController::class, 'index'])->name('income.index');
        Route::post('income', [IncomeController::class, 'store'])->name('income.store');

        Route::apiResource('expenses', ExpenseController::class)->except(['destroy']);
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        Route::post('expenses/{expense}/reject', [ExpenseController::class, 'reject'])->name('expenses.reject');
        Route::post('expenses/{expense}/pay', [ExpenseController::class, 'pay'])->name('expenses.pay');
        Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
        Route::post('expenses/{expense}/attachments', [ExpenseController::class, 'storeAttachment'])->name('expenses.attachments.store');
        Route::delete('expenses/{expense}/attachments/{attachment}', [ExpenseController::class, 'destroyAttachment'])->name('expenses.attachments.destroy');

        Route::apiResource('financial-transactions', FinancialTransactionController::class)->only(['index', 'show']);
        Route::post('financial-transactions/transfer', [FinancialTransactionController::class, 'transfer'])->name('financial-transactions.transfer');
        Route::post('financial-transactions/adjustment', [FinancialTransactionController::class, 'adjustment'])->name('financial-transactions.adjustment');

        Route::get('accounting/dashboard', [AccountingDashboardController::class, 'summary'])->name('accounting.dashboard');
        Route::get('accounting/reports/revenue', [AccountingReportController::class, 'revenue'])->name('accounting.reports.revenue');
        Route::get('accounting/reports/expenses', [AccountingReportController::class, 'expenses'])->name('accounting.reports.expenses');
        Route::get('accounting/reports/profit-loss', [AccountingReportController::class, 'profitLoss'])->name('accounting.reports.profit-loss');
        Route::get('accounting/reports/cash-flow', [AccountingReportController::class, 'cashFlow'])->name('accounting.reports.cash-flow');

        Route::get('accounting/periods', [AccountingPeriodController::class, 'index'])->name('accounting.periods.index');
        Route::post('accounting/periods/close', [AccountingPeriodController::class, 'close'])->name('accounting.periods.close');

        Route::get('settings/accounting', [AccountingSettingsController::class, 'show'])->name('settings.accounting.show');
        Route::post('settings/accounting', [AccountingSettingsController::class, 'update'])->name('settings.accounting.update');

        /*
        |----------------------------------------------------------------------
        | Assets — School Asset Lifecycle Management
        |----------------------------------------------------------------------
        |
        | Configuration (categories/locations/departments/suppliers/repair
        | shops) alongside the Asset lifecycle itself and its Issue/Repair/
        | Maintenance satellites. Access is gated per-route via the assets.*
        | permissions (AssetPolicy and friends), same pattern as Billing/
        | Accounting above.
        |
        */

        Route::apiResource('asset-categories', AssetCategoryController::class);
        Route::apiResource('asset-locations', AssetLocationController::class);
        Route::apiResource('departments', DepartmentController::class);
        Route::apiResource('suppliers', SupplierController::class);
        Route::apiResource('repair-shops', RepairShopController::class);

        // Registered before the `assets/{asset}` wildcard resource below, so
        // literal segments like `assets/dashboard` are matched here first
        // instead of being swallowed as an {asset} route-model-binding
        // lookup for a non-numeric "dashboard"/"reports" id.
        Route::get('assets/dashboard', [AssetDashboardController::class, 'summary'])->name('assets.dashboard');
        Route::get('assets/reports/inventory', [AssetReportController::class, 'inventory'])->name('assets.reports.inventory');
        Route::get('assets/reports/status', [AssetReportController::class, 'status'])->name('assets.reports.status');
        Route::get('assets/reports/repairs', [AssetReportController::class, 'repairs'])->name('assets.reports.repairs');
        Route::get('assets/reports/repair-cost', [AssetReportController::class, 'repairCost'])->name('assets.reports.repair-cost');
        Route::get('assets/reports/maintenance', [AssetReportController::class, 'maintenance'])->name('assets.reports.maintenance');
        Route::get('assets/reports/assignments', [AssetReportController::class, 'assignments'])->name('assets.reports.assignments');
        Route::get('assets/reports/history', [AssetReportController::class, 'history'])->name('assets.reports.history');

        Route::apiResource('assets', AssetController::class);
        Route::post('assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
        Route::post('assets/{asset}/return', [AssetController::class, 'returnAsset'])->name('assets.return');
        Route::post('assets/{asset}/transfer', [AssetController::class, 'transfer'])->name('assets.transfer');
        Route::post('assets/{asset}/change-condition', [AssetController::class, 'changeCondition'])->name('assets.change-condition');
        Route::post('assets/{asset}/retire', [AssetController::class, 'retire'])->name('assets.retire');
        Route::post('assets/{asset}/dispose', [AssetController::class, 'dispose'])->name('assets.dispose');
        Route::post('assets/{asset}/mark-lost', [AssetController::class, 'markLost'])->name('assets.mark-lost');
        Route::post('assets/{asset}/mark-found', [AssetController::class, 'markFound'])->name('assets.mark-found');
        Route::get('assets/{asset}/history', [AssetController::class, 'history'])->name('assets.history');
        Route::get('assets/{asset}/assignments', [AssetController::class, 'assignments'])->name('assets.assignments');
        Route::get('assets/{asset}/transfers', [AssetController::class, 'transfers'])->name('assets.transfers');

        Route::get('assets/{asset}/documents', [AssetDocumentController::class, 'index'])->name('assets.documents.index');
        Route::post('assets/{asset}/documents', [AssetDocumentController::class, 'store'])->name('assets.documents.store');
        Route::delete('assets/{asset}/documents/{document}', [AssetDocumentController::class, 'destroy'])->name('assets.documents.destroy');

        Route::post('assets/{asset}/issues', [AssetIssueController::class, 'store'])->name('assets.issues.store');
        Route::apiResource('asset-issues', AssetIssueController::class)->only(['index', 'show', 'update']);
        Route::post('asset-issues/{asset_issue}/resolve', [AssetIssueController::class, 'resolve'])->name('asset-issues.resolve');

        Route::post('assets/{asset}/repairs', [AssetRepairController::class, 'store'])->name('assets.repairs.store');
        Route::apiResource('asset-repairs', AssetRepairController::class)->only(['index', 'show', 'update']);
        Route::post('asset-repairs/{asset_repair}/complete', [AssetRepairController::class, 'complete'])->name('asset-repairs.complete');
        Route::post('asset-repairs/{asset_repair}/decide', [AssetRepairController::class, 'decide'])->name('asset-repairs.decide');
        Route::post('asset-repairs/{asset_repair}/cancel', [AssetRepairController::class, 'cancel'])->name('asset-repairs.cancel');

        Route::post('assets/{asset}/maintenance', [AssetMaintenanceController::class, 'store'])->name('assets.maintenance.store');
        Route::apiResource('asset-maintenance', AssetMaintenanceController::class)->only(['index', 'show']);
        Route::post('asset-maintenance/{asset_maintenance}/complete', [AssetMaintenanceController::class, 'complete'])->name('asset-maintenance.complete');
        Route::post('asset-maintenance/{asset_maintenance}/cancel', [AssetMaintenanceController::class, 'cancel'])->name('asset-maintenance.cancel');

        // Student self-service — identity-gated (User::student()), not
        // permission-gated. See MyInvoiceController's docblock.
        Route::get('my-invoices', [MyInvoiceController::class, 'index'])->name('my-invoices.index');
        Route::get('my-invoices/{invoice}', [MyInvoiceController::class, 'show'])->name('my-invoices.show');
        Route::get('my-invoices/{invoice}/pdf', [MyInvoiceController::class, 'downloadPdf'])->name('my-invoices.pdf');

        // Student self-service — identity-gated, same pattern as my-invoices.
        Route::get('my-attendance', [MyAttendanceController::class, 'index'])->name('my-attendance.index');

        // Self-service — identity-gated (Staff/Student/User's own assignments), same pattern as my-invoices.
        Route::get('my-assets', [MyAssetController::class, 'index'])->name('my-assets.index');

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
