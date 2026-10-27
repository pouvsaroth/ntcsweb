<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Admin\AcademicProgramController;
use App\Http\Controllers\Api\V1\Admin\AcademicReportController;
use App\Http\Controllers\Api\V1\Admin\AcademicTermController;
use App\Http\Controllers\Api\V1\Admin\AcademicYearController;
use App\Http\Controllers\Api\V1\Admin\AccountController;
use App\Http\Controllers\Api\V1\Admin\AccountingDashboardController;
use App\Http\Controllers\Api\V1\Admin\AccountingPeriodController;
use App\Http\Controllers\Api\V1\Admin\AccountingReportController;
use App\Http\Controllers\Api\V1\Admin\AccountingSettingsController;
use App\Http\Controllers\Api\V1\Admin\ApplicantController;
use App\Http\Controllers\Api\V1\Admin\ApplicantDocumentController;
use App\Http\Controllers\Api\V1\Admin\ApprovalFlowController;
use App\Http\Controllers\Api\V1\Admin\ApprovalGroupController;
use App\Http\Controllers\Api\V1\Admin\ApprovalRequestController;
use App\Http\Controllers\Api\V1\Admin\AssetCategoryController;
use App\Http\Controllers\Api\V1\Admin\AssetController;
use App\Http\Controllers\Api\V1\Admin\AssetDashboardController;
use App\Http\Controllers\Api\V1\Admin\AssetDocumentController;
use App\Http\Controllers\Api\V1\Admin\AssetIssueController;
use App\Http\Controllers\Api\V1\Admin\AssetLocationController;
use App\Http\Controllers\Api\V1\Admin\AssetMaintenanceController;
use App\Http\Controllers\Api\V1\Admin\AssetRepairController;
use App\Http\Controllers\Api\V1\Admin\AssetReportController;
use App\Http\Controllers\Api\V1\Admin\AttendanceApprovalController;
use App\Http\Controllers\Api\V1\Admin\AttendanceController;
use App\Http\Controllers\Api\V1\Admin\AttendanceCorrectionController;
use App\Http\Controllers\Api\V1\Admin\AuditLogController;
use App\Http\Controllers\Api\V1\Admin\BillingDashboardController;
use App\Http\Controllers\Api\V1\Admin\BookCategoryController;
use App\Http\Controllers\Api\V1\Admin\BookController;
use App\Http\Controllers\Api\V1\Admin\BranchController;
use App\Http\Controllers\Api\V1\Admin\BuildingController;
use App\Http\Controllers\Api\V1\Admin\CandidateSelectionController;
use App\Http\Controllers\Api\V1\Admin\ClassChangeRequestController;
use App\Http\Controllers\Api\V1\Admin\ClassroomController;
use App\Http\Controllers\Api\V1\Admin\ClassroomTableController;
use App\Http\Controllers\Api\V1\Admin\CoursePackageController;
use App\Http\Controllers\Api\V1\Admin\CurrencyRateController;
use App\Http\Controllers\Api\V1\Admin\CurriculumController;
use App\Http\Controllers\Api\V1\Admin\DatabaseBackupController;
use App\Http\Controllers\Api\V1\Admin\DepartmentController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentController;
use App\Http\Controllers\Api\V1\Admin\EnrollmentPackageController;
use App\Http\Controllers\Api\V1\Admin\EvaluationFormController;
use App\Http\Controllers\Api\V1\Admin\ExamApplicationController;
use App\Http\Controllers\Api\V1\Admin\ExamScoreController;
use App\Http\Controllers\Api\V1\Admin\ExpenseController;
use App\Http\Controllers\Api\V1\Admin\FinancialTransactionController;
use App\Http\Controllers\Api\V1\Admin\FormCategoryController;
use App\Http\Controllers\Api\V1\Admin\FormTemplateController;
use App\Http\Controllers\Api\V1\Admin\GeneralSettingsController;
use App\Http\Controllers\Api\V1\Admin\GradeLevelController;
use App\Http\Controllers\Api\V1\Admin\AssessmentSchemeController;
use App\Http\Controllers\Api\V1\Admin\ExamScheduleController;
use App\Http\Controllers\Api\V1\Admin\GradebookController;
use App\Http\Controllers\Api\V1\Admin\ResultApprovalController;
use App\Http\Controllers\Api\V1\Admin\StudentResultsController;
use App\Http\Controllers\Api\V1\Admin\HolidayController;
use App\Http\Controllers\Api\V1\Admin\IncomeController;
use App\Http\Controllers\Api\V1\Admin\InterviewController;
use App\Http\Controllers\Api\V1\Admin\InterviewEvaluationController;
use App\Http\Controllers\Api\V1\Admin\InvoiceController;
use App\Http\Controllers\Api\V1\Admin\JobGradeController;
use App\Http\Controllers\Api\V1\Admin\JobLevelController;
use App\Http\Controllers\Api\V1\Admin\JobPositionController;
use App\Http\Controllers\Api\V1\Admin\JobPostingController;
use App\Http\Controllers\Api\V1\Admin\KpiController;
use App\Http\Controllers\Api\V1\Admin\LanguageController;
use App\Http\Controllers\Api\V1\Admin\LeaveBalanceController;
use App\Http\Controllers\Api\V1\Admin\LeavePolicyController;
use App\Http\Controllers\Api\V1\Admin\LeaveReportController;
use App\Http\Controllers\Api\V1\Admin\LeaveRequestController;
use App\Http\Controllers\Api\V1\Admin\LeaveTypeController;
use App\Http\Controllers\Api\V1\Admin\LessonPlanController;
use App\Http\Controllers\Api\V1\Admin\LookupCategoryController;
use App\Http\Controllers\Api\V1\Admin\LookupValueController;
use App\Http\Controllers\Api\V1\Admin\MakeUpClassRequestController;
use App\Http\Controllers\Api\V1\Admin\ManpowerRequestController;
use App\Http\Controllers\Api\V1\Admin\MonthlyInvoiceController;
use App\Http\Controllers\Api\V1\Admin\MonthlyPaymentAlertController;
use App\Http\Controllers\Api\V1\Admin\TermStudyAlertController;
use App\Http\Controllers\Api\V1\Admin\NotificationGroupController;
use App\Http\Controllers\Api\V1\Admin\OfferLetterController;
use App\Http\Controllers\Api\V1\Admin\OrganizationChartController;
use App\Http\Controllers\Api\V1\Admin\OvertimeRequestController;
use App\Http\Controllers\Api\V1\Admin\PaymentController;
use App\Http\Controllers\Api\V1\Admin\PayrollComponentController;
use App\Http\Controllers\Api\V1\Admin\PayrollRulesController;
use App\Http\Controllers\Api\V1\Admin\PayrollRunController;
use App\Http\Controllers\Api\V1\Admin\PayrollStaffController;
use App\Http\Controllers\Api\V1\Admin\PerformanceCycleController;
use App\Http\Controllers\Api\V1\Admin\PerformanceGoalController;
use App\Http\Controllers\Api\V1\Admin\PerformanceReviewController;
use App\Http\Controllers\Api\V1\Admin\PositionController;
use App\Http\Controllers\Api\V1\Admin\ProductController;
use App\Http\Controllers\Api\V1\Admin\ProductVariantController;
use App\Http\Controllers\Api\V1\Admin\ProgramController as AdminProgramController;
use App\Http\Controllers\Api\V1\Admin\ProjectColumnController;
use App\Http\Controllers\Api\V1\Admin\ProjectController;
use App\Http\Controllers\Api\V1\Admin\ProjectLabelController;
use App\Http\Controllers\Api\V1\Admin\ProjectMilestoneController;
use App\Http\Controllers\Api\V1\Admin\ProjectTaskAttachmentController;
use App\Http\Controllers\Api\V1\Admin\ProjectTaskChecklistItemController;
use App\Http\Controllers\Api\V1\Admin\ProjectTaskCommentController;
use App\Http\Controllers\Api\V1\Admin\ProjectTaskController;
use App\Http\Controllers\Api\V1\Admin\ProjectTaskDependencyController;
use App\Http\Controllers\Api\V1\Admin\PromotionRecommendationController;
use App\Http\Controllers\Api\V1\Admin\RemedialTeachingController;
use App\Http\Controllers\Api\V1\Admin\RepairShopController;
use App\Http\Controllers\Api\V1\Admin\ResignationRequestController;
use App\Http\Controllers\Api\V1\Admin\RoleController;
use App\Http\Controllers\Api\V1\Admin\SalaryStructureController;
use App\Http\Controllers\Api\V1\Admin\ScheduleChangeController;
use App\Http\Controllers\Api\V1\Admin\SchoolCalendarController;
use App\Http\Controllers\Api\V1\Admin\SchoolClassController;
use App\Http\Controllers\Api\V1\Admin\SchoolDocumentsController;
use App\Http\Controllers\Api\V1\Admin\SchoolSettingsController;
use App\Http\Controllers\Api\V1\Admin\ShiftController;
use App\Http\Controllers\Api\V1\Admin\StaffAttendanceController;
use App\Http\Controllers\Api\V1\Admin\StaffController;
use App\Http\Controllers\Api\V1\Admin\StaffLoanController;
use App\Http\Controllers\Api\V1\Admin\StaffPayComponentController;
use App\Http\Controllers\Api\V1\Admin\StaffSalaryController;
use App\Http\Controllers\Api\V1\Admin\StaffStatusHistoryController;
use App\Http\Controllers\Api\V1\Admin\StudentController;
use App\Http\Controllers\Api\V1\Admin\StudentFeedbackController;
use App\Http\Controllers\Api\V1\Admin\StudentImportController;
use App\Http\Controllers\Api\V1\Admin\StudentPromotionController;
use App\Http\Controllers\Api\V1\Admin\StudentRecordController;
use App\Http\Controllers\Api\V1\Admin\StudentRegistrationController;
use App\Http\Controllers\Api\V1\Admin\SupplierController;
use App\Http\Controllers\Api\V1\Admin\TeacherController;
use App\Http\Controllers\Api\V1\Admin\TeacherDocumentController;
use App\Http\Controllers\Api\V1\Admin\TeacherScheduleController;
use App\Http\Controllers\Api\V1\Admin\TeamController;
use App\Http\Controllers\Api\V1\Admin\TimetableController;
use App\Http\Controllers\Api\V1\Admin\UserController;
use App\Http\Controllers\Api\V1\Admin\VideoController;
use App\Http\Controllers\Api\V1\Admin\WorkScheduleController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\EmailVerificationController;
use App\Http\Controllers\Api\V1\Auth\PasswordController;
use App\Http\Controllers\Api\V1\Auth\SupportViewController;
use App\Http\Controllers\Api\V1\Auth\DemoSignInController;
use App\Http\Controllers\Api\V1\GeographyController;
use App\Http\Controllers\Api\V1\LookupController;
use App\Http\Controllers\Api\V1\MyApprovalRequestController;
use App\Http\Controllers\Api\V1\MyAssetController;
use App\Http\Controllers\Api\V1\MyAttendanceController;
use App\Http\Controllers\Api\V1\MyAttendanceCorrectionController;
use App\Http\Controllers\Api\V1\MyClassChangeRequestController;
use App\Http\Controllers\Api\V1\MyExamApplicationController;
use App\Http\Controllers\Api\V1\MyExamScoreController;
use App\Http\Controllers\Api\V1\MyInvoiceController;
use App\Http\Controllers\Api\V1\MyLeaveRequestController;
use App\Http\Controllers\Api\V1\MyMakeUpClassRequestController;
use App\Http\Controllers\Api\V1\MyMonthlyPaymentAlertController;
use App\Http\Controllers\Api\V1\MyOvertimeRequestController;
use App\Http\Controllers\Api\V1\MyPerformanceReviewController;
use App\Http\Controllers\Api\V1\MyPushSubscriptionController;
use App\Http\Controllers\Api\V1\MyResignationRequestController;
use App\Http\Controllers\Api\V1\MyStaffAttendanceController;
use App\Http\Controllers\Api\V1\MyStudentFeedbackController;
use App\Http\Controllers\Api\V1\MyVideoController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Public\CareerController;
use App\Http\Controllers\Api\V1\Public\CoursePackageController as PublicCoursePackageController;
use App\Http\Controllers\Api\V1\Public\DownloadController as PublicDownloadController;
use App\Http\Controllers\Api\V1\Public\EnrollmentInquiryController;
use App\Http\Controllers\Api\V1\Public\GalleryController as PublicGalleryController;
use App\Http\Controllers\Api\V1\Public\HomeSlideController as PublicHomeSlideController;
use App\Http\Controllers\Api\V1\Public\KhqrController as PublicKhqrController;
use App\Http\Controllers\Api\V1\Public\PlanController as PublicPlanController;
use App\Http\Controllers\Api\V1\Public\ProgramController as PublicProgramController;
use App\Http\Controllers\Api\V1\Public\PromotionController as PublicPromotionController;
use App\Http\Controllers\Api\V1\Public\ScheduleController as PublicScheduleController;
use App\Http\Controllers\Api\V1\Public\SignupController as PublicSignupController;
use App\Http\Controllers\Api\V1\Public\DemoController as PublicDemoController;
use App\Http\Controllers\Api\V1\Public\SiteSettingsController;
use App\Http\Controllers\Api\V1\Public\StudentRegistrationController as PublicStudentRegistrationController;
use App\Http\Controllers\Api\V1\Public\VideoLessonController;
use App\Http\Controllers\Api\V1\Public\WebsiteVisitController;
use App\Http\Controllers\Api\V1\SupportChatController;
use App\Http\Controllers\Api\V1\TenantDirectoryController;
use App\Http\Controllers\Api\V1\TranslationOverrideController;
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
            Route::post('login/select-tenant', [AuthController::class, 'selectTenant'])->name('login.select-tenant');
            // A back office support view's one-time ticket (docs/backoffice/stage-6-support.md).
            Route::post('support-view', [SupportViewController::class, 'enter'])->name('support-view');
            // A website demo's one-time ticket (docs/backoffice/stage-12-demo.md).
            Route::post('demo', [DemoSignInController::class, 'enter'])->name('demo');
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
        // Every route below is implicitly tenant-scoped: Staff, Student,
        // Classroom, Book, SchoolClass, and Enrollment all use BelongsToTenant,
        // so both the index queries and the {param} route-model bindings only
        // ever see the current school's rows — a stray id for another
        // tenant's record 404s the same way a nonexistent id would.
        // Support chat with NewTech (docs/backoffice/stage-10-support-chat.md): staff only.
        Route::prefix('support-chat')->name('support-chat.')->group(function () {
            Route::get('/', [SupportChatController::class, 'show'])->name('show');
            Route::get('unread', [SupportChatController::class, 'unread'])->name('unread');
            Route::post('/', [SupportChatController::class, 'send'])->middleware('throttle:30,1')->name('send');
            Route::post('read', [SupportChatController::class, 'read'])->name('read');
            Route::get('files/{message}/{index}', [SupportChatController::class, 'file'])->whereNumber(['message', 'index'])->name('file');
        });

        // Student record (docs/academics/student-record.md).
        Route::get('students/{student}/record', [StudentRecordController::class, 'show'])->name('students.record');
        Route::post('students/{student}/status', [StudentRecordController::class, 'changeStatus'])->name('students.status');
        Route::post('classes/{class}/assign-students', [StudentRecordController::class, 'assignToClass'])->name('classes.assign-students');
        Route::apiResource('students', StudentController::class);

        // Bulk CSV import from the legacy `t_student` system — a separate
        // resource from `students` itself since an import is a background
        // job with its own lifecycle (pending/processing/completed/failed),
        // not a student record. index/store/show only: an import is never
        // updated or deleted once uploaded.
        Route::apiResource('student-imports', StudentImportController::class)
            ->only(['index', 'store', 'show']);

        // The public self-registration wizard's approval queue — pending
        // Students awaiting confirmation of a cash payment. Separate from
        // `students` itself since "list/approve/reject the pending ones" is
        // a distinct workflow, not a general Student CRUD concern. See
        // StudentRegistrationService.
        Route::get('student-registrations', [StudentRegistrationController::class, 'index'])->name('student-registrations.index');
        Route::get('student-registrations/{student}', [StudentRegistrationController::class, 'show'])->name('student-registrations.show');
        Route::post('student-registrations/{student}/approve', [StudentRegistrationController::class, 'approve'])->name('student-registrations.approve');
        Route::post('student-registrations/{student}/reject', [StudentRegistrationController::class, 'reject'])->name('student-registrations.reject');

        Route::apiResource('buildings', BuildingController::class);
        Route::apiResource('classrooms', ClassroomController::class);
        Route::post('classroom-tables/bulk', [ClassroomTableController::class, 'bulkStore'])->name('classroom-tables.bulk');
        Route::apiResource('classroom-tables', ClassroomTableController::class);
        Route::apiResource('book-categories', BookCategoryController::class);
        Route::apiResource('books', BookController::class);

        // Named {class}, not {schoolClass}: matches the SchoolClass model's
        // $class parameter in each controller method and keeps the URL
        // ("/api/v1/classes/{class}") reading naturally despite the model
        // itself being named SchoolClass to dodge PHP's `class` keyword.
        Route::apiResource('classes', SchoolClassController::class)->parameters(['classes' => 'class']);
        Route::get('classes/{class}/available-tables', [SchoolClassController::class, 'availableTables'])->name('classes.available-tables');

        // Class > Students > Note: save the notes of many students at once.
        Route::put('enrollment-notes', [EnrollmentController::class, 'saveNotes'])->name('enrollments.notes');
        // Class > Students > Export Excel: the checked students, in the order shown.
        Route::get('enrollment-export', [EnrollmentController::class, 'export'])->name('enrollments.export');
        Route::apiResource('enrollments', EnrollmentController::class)->except(['store']);
        Route::post('enrollments/package', [EnrollmentPackageController::class, 'store'])->name('enrollments.package.store');
        Route::post('enrollments/{enrollment}/cancel', [EnrollmentController::class, 'cancel'])->name('enrollments.cancel');
        Route::post('enrollments/{enrollment}/transfer', [EnrollmentController::class, 'transfer'])->name('enrollments.transfer');
        Route::patch('enrollments/{enrollment}/table', [EnrollmentController::class, 'changeTable'])->name('enrollments.change-table');
        Route::post('enrollments/{enrollment}/status', [EnrollmentController::class, 'changeStatus'])->name('enrollments.status.update');
        Route::get('enrollments/{enrollment}/status-history', [EnrollmentController::class, 'statusHistory'])->name('enrollments.status-history');
        Route::get('enrollments/{enrollment}/transfer-history', [EnrollmentController::class, 'transferHistory'])->name('enrollments.transfer-history');

        /*
        |----------------------------------------------------------------------
        | Academic — Program / Package / Class linkage
        |----------------------------------------------------------------------
        |
        | Study Mode / Academic Program / Course Package / Academic Year —
        | the configurable catalog layer package-based enrollment is built
        | on. `AcademicProgram` is deliberately not named/routed as
        | `programs` — that resource above is the unrelated public
        | marketing catalog (see AcademicProgram's own docblock). There is
        | no separate "Course" resource — a Course Package bundles Books
        | directly (see Book::coursePackages()).
        |
        */

        Route::apiResource('academic-programs', AcademicProgramController::class);
        Route::apiResource('course-packages', CoursePackageController::class);
        Route::apiResource('videos', VideoController::class);
        Route::apiResource('academic-years', AcademicYearController::class);

        // School setup (docs/academics/school-setup.md): terms, grade levels,
        // curriculum, school calendar, promotion rules and promoting students.
        Route::apiResource('academic-terms', AcademicTermController::class)->except(['show']);
        Route::post('grade-levels/defaults', [GradeLevelController::class, 'seedDefaults'])->name('grade-levels.defaults');
        Route::apiResource('grade-levels', GradeLevelController::class)->except(['show']);
        Route::get('curriculum', [CurriculumController::class, 'show'])->name('curriculum.show');
        Route::put('curriculum/subjects', [CurriculumController::class, 'saveSubject'])->name('curriculum.subjects.save');
        Route::delete('curriculum/subjects', [CurriculumController::class, 'removeSubject'])->name('curriculum.subjects.remove');
        Route::post('curriculum/lessons', [CurriculumController::class, 'storeLesson'])->name('curriculum.lessons.store');
        Route::put('curriculum/lessons/{lesson}', [CurriculumController::class, 'updateLesson'])->name('curriculum.lessons.update');
        Route::delete('curriculum/lessons/{lesson}', [CurriculumController::class, 'destroyLesson'])->name('curriculum.lessons.destroy');
        Route::get('school-calendar', [SchoolCalendarController::class, 'index'])->name('school-calendar.index');
        Route::post('school-calendar', [SchoolCalendarController::class, 'store'])->name('school-calendar.store');
        Route::put('school-calendar/{schoolCalendarEvent}', [SchoolCalendarController::class, 'update'])->name('school-calendar.update');
        Route::delete('school-calendar/{schoolCalendarEvent}', [SchoolCalendarController::class, 'destroy'])->name('school-calendar.destroy');
        Route::get('promotion-rules', [StudentPromotionController::class, 'rules'])->name('promotion-rules.index');
        Route::put('promotion-rules', [StudentPromotionController::class, 'saveRule'])->name('promotion-rules.save');
        Route::delete('promotion-rules/{promotionRule}', [StudentPromotionController::class, 'destroyRule'])->name('promotion-rules.destroy');
        // Examination: assessment setup and exam schedule (docs/academics/gradebook.md).
        Route::post('assessment-schemes/{assessmentScheme}/starter', [AssessmentSchemeController::class, 'starter'])->name('assessment-schemes.starter');
        Route::apiResource('assessment-schemes', AssessmentSchemeController::class)->except(['show']);
        Route::apiResource('exam-schedules', ExamScheduleController::class)->except(['show']);
        Route::get('gradebook/classes', [GradebookController::class, 'classes'])->name('gradebook.classes');
        Route::get('gradebook/sheet', [GradebookController::class, 'sheet'])->name('gradebook.sheet');
        Route::put('gradebook/sheet', [GradebookController::class, 'save'])->name('gradebook.save');
        Route::get('gradebook/validation', [GradebookController::class, 'validation'])->name('gradebook.validation');
        Route::get('gradebook/results', [GradebookController::class, 'results'])->name('gradebook.results');
        // Result approval and grade corrections (7c), decided through eApprovals.
        Route::get('gradebook/sheets', [ResultApprovalController::class, 'sheets'])->name('gradebook.sheets');
        Route::post('gradebook/sheets/submit', [ResultApprovalController::class, 'submit'])->name('gradebook.sheets.submit');
        Route::post('gradebook/sheets/{resultSheet}/approve', [ResultApprovalController::class, 'approve'])->name('gradebook.sheets.approve');
        Route::post('gradebook/sheets/{resultSheet}/return', [ResultApprovalController::class, 'return'])->name('gradebook.sheets.return');
        Route::get('gradebook/corrections', [ResultApprovalController::class, 'corrections'])->name('gradebook.corrections');
        Route::post('gradebook/corrections', [ResultApprovalController::class, 'storeCorrection'])->name('gradebook.corrections.store');
        Route::post('gradebook/corrections/{gradeCorrection}/approve', [ResultApprovalController::class, 'approveCorrection'])->name('gradebook.corrections.approve');
        Route::post('gradebook/corrections/{gradeCorrection}/reject', [ResultApprovalController::class, 'rejectCorrection'])->name('gradebook.corrections.reject');
        Route::delete('gradebook/corrections/{gradeCorrection}', [ResultApprovalController::class, 'destroyCorrection'])->name('gradebook.corrections.destroy');
        Route::get('gradebook/history', [ResultApprovalController::class, 'history'])->name('gradebook.history');
        // Transcript, progress and exports (7d).
        Route::get('gradebook/students', [StudentResultsController::class, 'students'])->name('gradebook.students');
        Route::get('gradebook/transcript', [StudentResultsController::class, 'transcript'])->name('gradebook.transcript');
        Route::get('gradebook/transcript/pdf', [StudentResultsController::class, 'transcriptPdf'])->name('gradebook.transcript.pdf');
        Route::get('gradebook/progress/student', [StudentResultsController::class, 'studentProgress'])->name('gradebook.progress.student');
        Route::get('gradebook/progress/class', [StudentResultsController::class, 'classProgress'])->name('gradebook.progress.class');
        Route::get('gradebook/export/report-cards', [StudentResultsController::class, 'reportCards'])->name('gradebook.export.report-cards');
        Route::get('gradebook/export/score-sheet', [StudentResultsController::class, 'scoreSheet'])->name('gradebook.export.score-sheet');
        // Remedial Teaching (7e).
        Route::get('gradebook/remedial', [RemedialTeachingController::class, 'index'])->name('gradebook.remedial.index');
        Route::post('gradebook/remedial', [RemedialTeachingController::class, 'store'])->name('gradebook.remedial.store');
        Route::put('gradebook/remedial/{remedialSession}', [RemedialTeachingController::class, 'update'])->name('gradebook.remedial.update');
        Route::delete('gradebook/remedial/{remedialSession}', [RemedialTeachingController::class, 'destroy'])->name('gradebook.remedial.destroy');
        Route::get('student-promotions/classes', [StudentPromotionController::class, 'classes'])->name('student-promotions.classes');
        Route::get('student-promotions', [StudentPromotionController::class, 'show'])->name('student-promotions.show');
        Route::post('student-promotions', [StudentPromotionController::class, 'apply'])->name('student-promotions.apply');

        Route::get('academic-reports/enrollments', [AcademicReportController::class, 'enrollments'])->name('academic-reports.enrollments');
        Route::get('academic-reports/program-revenue', [AcademicReportController::class, 'programRevenue'])->name('academic-reports.program-revenue');
        Route::get('academic-reports/package-sales', [AcademicReportController::class, 'packageSales'])->name('academic-reports.package-sales');
        Route::get('academic-reports/classes', [AcademicReportController::class, 'classReport'])->name('academic-reports.classes');
        Route::get('students/{student}/financial-summary', [AcademicReportController::class, 'studentFinancial'])->name('students.financial-summary');

        // Attendance — history/review list, plus per-class "take attendance"
        // (roster + save) nested under the class it belongs to. See
        // AttendanceService/AttendancePolicy/SchoolClassPolicy::recordAttendance().
        Route::apiResource('attendance', AttendanceController::class)->only(['index', 'show']);
        Route::get('classes/{class}/attendance', [AttendanceController::class, 'roster'])->name('classes.attendance.roster');
        Route::post('classes/{class}/attendance', [AttendanceController::class, 'store'])->name('classes.attendance.store');
        Route::get('classes/{class}/attendance-summary', [AttendanceController::class, 'summary'])->name('classes.attendance.summary');
        Route::get('attendance-summary', [AttendanceController::class, 'summaryAcrossClasses'])->name('attendance.summary');

        // Student-submitted leave/permission requests — approving one syncs
        // into AttendanceRecord (status Excused) via LeaveRequestService.
        Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'show', 'store']);
        Route::post('leave-requests/{leave_request}/approve', [LeaveRequestController::class, 'approve'])->name('leave-requests.approve');
        Route::post('leave-requests/{leave_request}/reject', [LeaveRequestController::class, 'reject'])->name('leave-requests.reject');

        // Staff-submitted resignation requests — see ResignationRequestService.
        Route::apiResource('resignation-requests', ResignationRequestController::class)->only(['index', 'show']);
        Route::post('resignation-requests/{resignation_request}/approve', [ResignationRequestController::class, 'approve'])->name('resignation-requests.approve');
        Route::post('resignation-requests/{resignation_request}/reject', [ResignationRequestController::class, 'reject'])->name('resignation-requests.reject');

        // HRM > Recruitment > Manpower request — decided in E-Approvals like the above.
        Route::apiResource('manpower-requests', ManpowerRequestController::class);
        Route::post('manpower-requests/{manpower_request}/approve', [ManpowerRequestController::class, 'approve'])->name('manpower-requests.approve');
        Route::post('manpower-requests/{manpower_request}/reject', [ManpowerRequestController::class, 'reject'])->name('manpower-requests.reject');
        Route::apiResource('job-positions', JobPositionController::class);
        Route::apiResource('job-postings', JobPostingController::class);
        Route::apiResource('applicants', ApplicantController::class);
        Route::get('applicant-documents/{applicant_document}/download', [ApplicantDocumentController::class, 'download'])->name('applicant-documents.download');
        Route::apiResource('applicant-documents', ApplicantDocumentController::class)->only(['index', 'store', 'destroy']);
        Route::get('interviews/interviewers', [InterviewController::class, 'interviewers'])->name('interviews.interviewers');
        Route::apiResource('interviews', InterviewController::class);
        Route::apiResource('interview-evaluations', InterviewEvaluationController::class)->only(['index', 'store', 'destroy']);
        Route::get('candidate-selection', CandidateSelectionController::class)->name('candidate-selection');
        Route::apiResource('offer-letters', OfferLetterController::class);
        Route::post('offer-letters/{offer_letter}/hire', [OfferLetterController::class, 'hire'])->name('offer-letters.hire');

        // HRM > Leave Management — leave set-up.
        Route::apiResource('leave-types', LeaveTypeController::class);
        Route::apiResource('leave-policies', LeavePolicyController::class);
        Route::get('leave-balances', [LeaveBalanceController::class, 'index'])->name('leave-balances.index');
        Route::get('leave-balances/carry-forward', [LeaveBalanceController::class, 'carried'])->name('leave-balances.carried');
        Route::post('leave-balances/carry-forward', [LeaveBalanceController::class, 'carryForward'])->name('leave-balances.carry-forward');
        Route::post('leave-balances/adjustments', [LeaveBalanceController::class, 'adjust'])->name('leave-balances.adjust');
        Route::get('leave-balances/{staff}', [LeaveBalanceController::class, 'show'])->name('leave-balances.show');
        Route::delete('leave-balance-entries/{leave_balance_entry}', [LeaveBalanceController::class, 'destroyEntry'])->name('leave-balance-entries.destroy');
        Route::get('leave-calendar', [LeaveReportController::class, 'calendar'])->name('leave-calendar');
        Route::get('leave-reports', [LeaveReportController::class, 'report'])->name('leave-reports');
        Route::get('leave-workflow', [LeaveReportController::class, 'workflow'])->name('leave-workflow');

        // HRM > Payroll — salary set-up.
        Route::apiResource('payroll-components', PayrollComponentController::class);
        Route::apiResource('salary-structures', SalaryStructureController::class);
        Route::get('staff-salaries', [StaffSalaryController::class, 'index'])->name('staff-salaries.index');
        Route::post('staff-salaries', [StaffSalaryController::class, 'store'])->name('staff-salaries.store');
        Route::get('staff-salaries/history/{staff}', [StaffSalaryController::class, 'history'])->name('staff-salaries.history');
        Route::put('staff-salaries/{staff_salary}', [StaffSalaryController::class, 'update'])->name('staff-salaries.update');
        Route::delete('staff-salaries/{staff_salary}', [StaffSalaryController::class, 'destroy'])->name('staff-salaries.destroy');
        Route::apiResource('staff-pay-components', StaffPayComponentController::class)->except(['show']);

        // HRM > Payroll — rules (overtime, attendance deduction, tax, NSSF, loans).
        Route::get('payroll-rules', [PayrollRulesController::class, 'show'])->name('payroll-rules.show');
        Route::put('payroll-rules/settings', [PayrollRulesController::class, 'updateSettings'])->name('payroll-rules.settings');
        Route::put('payroll-rules/tax-brackets', [PayrollRulesController::class, 'replaceBrackets'])->name('payroll-rules.tax-brackets');
        Route::get('payroll-rules/preview', [PayrollRulesController::class, 'preview'])->name('payroll-rules.preview');
        Route::post('social-security-schemes', [PayrollRulesController::class, 'storeScheme'])->name('social-security-schemes.store');
        Route::put('social-security-schemes/{social_security_scheme}', [PayrollRulesController::class, 'updateScheme'])->name('social-security-schemes.update');
        Route::delete('social-security-schemes/{social_security_scheme}', [PayrollRulesController::class, 'destroyScheme'])->name('social-security-schemes.destroy');
        Route::get('staff-payroll-profiles', [PayrollStaffController::class, 'profiles'])->name('staff-payroll-profiles.index');
        Route::put('staff-payroll-profiles/{staff}', [PayrollStaffController::class, 'updateProfile'])->name('staff-payroll-profiles.update');
        Route::get('payroll-overtime', [PayrollStaffController::class, 'overtime'])->name('payroll-overtime');
        Route::get('payroll-attendance-deductions', [PayrollStaffController::class, 'deductions'])->name('payroll-attendance-deductions');
        Route::apiResource('staff-loans', StaffLoanController::class);
        Route::post('staff-loans/{staff_loan}/cancel', [StaffLoanController::class, 'cancel'])->name('staff-loans.cancel');
        Route::post('staff-loans/{staff_loan}/repayments', [StaffLoanController::class, 'repay'])->name('staff-loans.repay');
        Route::delete('staff-loan-repayments/{staff_loan_repayment}', [StaffLoanController::class, 'destroyRepayment'])->name('staff-loan-repayments.destroy');

        // HRM > Payroll — runs and payslips.
        Route::get('payroll-runs/pay-accounts', [PayrollRunController::class, 'payAccounts'])->name('payroll-runs.pay-accounts');
        Route::apiResource('payroll-runs', PayrollRunController::class)->only(['index', 'store', 'show', 'update']);
        Route::post('payroll-runs/{payroll_run}/recalculate', [PayrollRunController::class, 'recalculate'])->name('payroll-runs.recalculate');
        Route::post('payroll-runs/{payroll_run}/submit', [PayrollRunController::class, 'submit'])->name('payroll-runs.submit');
        Route::post('payroll-runs/{payroll_run}/approve', [PayrollRunController::class, 'approve'])->name('payroll-runs.approve');
        Route::post('payroll-runs/{payroll_run}/reject', [PayrollRunController::class, 'reject'])->name('payroll-runs.reject');
        Route::post('payroll-runs/{payroll_run}/pay', [PayrollRunController::class, 'pay'])->name('payroll-runs.pay');
        Route::post('payroll-runs/{payroll_run}/cancel', [PayrollRunController::class, 'cancel'])->name('payroll-runs.cancel');
        Route::get('payslips', [PayrollRunController::class, 'payslips'])->name('payslips.index');
        Route::get('payslips/{payslip}', [PayrollRunController::class, 'payslip'])->name('payslips.show');
        Route::put('payslips/{payslip}/adjustment', [PayrollRunController::class, 'adjust'])->name('payslips.adjust');

        // HRM > Performance Management — set-up.
        Route::get('performance-settings', [PerformanceCycleController::class, 'settings'])->name('performance-settings.show');
        Route::put('performance-settings', [PerformanceCycleController::class, 'updateSettings'])->name('performance-settings.update');
        Route::apiResource('performance-cycles', PerformanceCycleController::class)->except(['show']);
        Route::apiResource('kpis', KpiController::class)->except(['show']);
        Route::apiResource('evaluation-forms', EvaluationFormController::class);
        Route::apiResource('performance-goals', PerformanceGoalController::class)->except(['show']);

        // HRM > Performance Management — reviews and scores.
        Route::get('performance-cycles/{performance_cycle}/candidates', [PerformanceReviewController::class, 'candidates'])->name('performance-cycles.candidates');
        Route::post('performance-cycles/{performance_cycle}/launch', [PerformanceReviewController::class, 'launch'])->name('performance-cycles.launch');
        Route::get('performance-scores', [PerformanceReviewController::class, 'scores'])->name('performance-scores');
        Route::apiResource('performance-reviews', PerformanceReviewController::class)->only(['index', 'show', 'update', 'destroy']);
        Route::put('performance-reviews/{performance_review}/kpis', [PerformanceReviewController::class, 'replaceKpis'])->name('performance-reviews.kpis');
        Route::post('performance-reviews/{performance_review}/send-to-manager', [PerformanceReviewController::class, 'sendToManager'])->name('performance-reviews.send-to-manager');
        Route::post('performance-reviews/{performance_review}/reopen', [PerformanceReviewController::class, 'reopen'])->name('performance-reviews.reopen');

        // HRM > Performance Management — promotion recommendations.
        Route::get('promotion-recommendations/options', [PromotionRecommendationController::class, 'options'])->name('promotion-recommendations.options');
        Route::get('promotion-recommendations/suggestions', [PromotionRecommendationController::class, 'suggestions'])->name('promotion-recommendations.suggestions');
        Route::get('promotion-recommendations/current/{staff}', [PromotionRecommendationController::class, 'current'])->name('promotion-recommendations.current');
        Route::get('promotion-recommendations', [PromotionRecommendationController::class, 'index'])->name('promotion-recommendations.index');
        Route::post('promotion-recommendations', [PromotionRecommendationController::class, 'store'])->name('promotion-recommendations.store');
        Route::post('promotion-recommendations/{promotion_recommendation}/approve', [PromotionRecommendationController::class, 'approve'])->name('promotion-recommendations.approve');
        Route::post('promotion-recommendations/{promotion_recommendation}/reject', [PromotionRecommendationController::class, 'reject'])->name('promotion-recommendations.reject');
        Route::post('promotion-recommendations/{promotion_recommendation}/cancel', [PromotionRecommendationController::class, 'cancel'])->name('promotion-recommendations.cancel');

        // HRM > Attendance & Time — staff attendance set-up.
        Route::apiResource('shifts', ShiftController::class);
        Route::apiResource('work-schedules', WorkScheduleController::class);
        // Academic > Lesson plans (docs/academics/lesson-plans.md).
        Route::prefix('lesson-plans')->name('lesson-plans.')->group(function () {
            Route::get('options', [LessonPlanController::class, 'options'])->name('options');
            Route::get('curriculum-lessons', [LessonPlanController::class, 'curriculumLessons'])->name('curriculum-lessons');
            Route::get('plans', [LessonPlanController::class, 'plans'])->name('plans.index');
            Route::post('plans', [LessonPlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}', [LessonPlanController::class, 'show'])->name('plans.show');
            Route::put('plans/{plan}', [LessonPlanController::class, 'update'])->name('plans.update');
            Route::delete('plans/{plan}', [LessonPlanController::class, 'destroy'])->name('plans.destroy');
            Route::post('plans/{plan}/submit', [LessonPlanController::class, 'submit'])->name('plans.submit');
            Route::post('plans/{plan}/review', [LessonPlanController::class, 'review'])->name('plans.review');
            Route::post('plans/{plan}/files', [LessonPlanController::class, 'upload'])->name('plans.files.store');
            Route::get('files/{file}', [LessonPlanController::class, 'download'])->name('files.show');
            Route::delete('files/{file}', [LessonPlanController::class, 'removeFile'])->name('files.destroy');
            Route::get('logs', [LessonPlanController::class, 'logs'])->name('logs.index');
            Route::post('logs', [LessonPlanController::class, 'storeLog'])->name('logs.store');
            Route::put('logs/{log}', [LessonPlanController::class, 'updateLog'])->name('logs.update');
            Route::delete('logs/{log}', [LessonPlanController::class, 'destroyLog'])->name('logs.destroy');
            Route::get('progress', [LessonPlanController::class, 'progress'])->name('progress');
            Route::get('delays', [LessonPlanController::class, 'delays'])->name('delays');
        });

        // Academic > Timetable (docs/academics/timetable.md).
        Route::prefix('timetable')->name('timetable.')->group(function () {
            Route::get('options', [TimetableController::class, 'options'])->name('options');
            Route::get('class', [TimetableController::class, 'forClass'])->name('class');
            Route::get('teacher', [TimetableController::class, 'forTeacher'])->name('teacher');
            Route::get('room', [TimetableController::class, 'forRoom'])->name('room');
            Route::get('subjects', [TimetableController::class, 'subjects'])->name('subjects');
            Route::get('conflicts', [TimetableController::class, 'conflicts'])->name('conflicts');
            Route::post('slots', [TimetableController::class, 'storeSlot'])->name('slots.store');
            Route::put('slots/{schedule}', [TimetableController::class, 'updateSlot'])->name('slots.update');
            Route::delete('slots/{schedule}', [TimetableController::class, 'destroySlot'])->name('slots.destroy');
            Route::get('changes', [ScheduleChangeController::class, 'index'])->name('changes.index');
            Route::post('changes', [ScheduleChangeController::class, 'store'])->name('changes.store');
            Route::post('changes/{changeRequest}/approve', [ScheduleChangeController::class, 'approve'])->whereNumber('changeRequest')->name('changes.approve');
            Route::post('changes/{changeRequest}/reject', [ScheduleChangeController::class, 'reject'])->whereNumber('changeRequest')->name('changes.reject');
            Route::post('changes/{changeRequest}/cancel', [ScheduleChangeController::class, 'cancel'])->whereNumber('changeRequest')->name('changes.cancel');
        });

        // HRM > Teacher (docs/hrm/teachers.md).
        Route::prefix('teachers')->name('teachers.')->group(function () {
            Route::get('/', [TeacherController::class, 'index'])->name('index');
            Route::get('options', [TeacherController::class, 'options'])->name('options');
            Route::get('qualifications', [TeacherController::class, 'qualifications'])->name('qualifications.index');
            Route::post('qualifications', [TeacherController::class, 'storeQualification'])->name('qualifications.store');
            Route::put('qualifications/{qualification}', [TeacherController::class, 'updateQualification'])->name('qualifications.update');
            Route::delete('qualifications/{qualification}', [TeacherController::class, 'destroyQualification'])->name('qualifications.destroy');
            Route::put('subjects', [TeacherController::class, 'saveSubjects'])->name('subjects.save');
            Route::get('assignments', [TeacherController::class, 'assignments'])->name('assignments.index');
            Route::post('assignments', [TeacherController::class, 'storeAssignment'])->name('assignments.store');
            Route::put('assignments/{assignment}', [TeacherController::class, 'updateAssignment'])->name('assignments.update');
            Route::delete('assignments/{assignment}', [TeacherController::class, 'destroyAssignment'])->name('assignments.destroy');
            Route::get('homerooms', [TeacherController::class, 'homerooms'])->name('homerooms.index');
            Route::put('homerooms/{class}', [TeacherController::class, 'saveHomeroom'])->name('homerooms.save');
            Route::get('load', [TeacherController::class, 'load'])->name('load');
            Route::get('availability', [TeacherScheduleController::class, 'availability'])->name('availability.index');
            Route::post('unavailability', [TeacherScheduleController::class, 'storeUnavailability'])->name('unavailability.store');
            Route::put('unavailability/{unavailability}', [TeacherScheduleController::class, 'updateUnavailability'])->name('unavailability.update');
            Route::delete('unavailability/{unavailability}', [TeacherScheduleController::class, 'destroyUnavailability'])->name('unavailability.destroy');
            Route::get('substitutions', [TeacherScheduleController::class, 'substitutions'])->name('substitutions.index');
            Route::get('substitutions/candidates', [TeacherScheduleController::class, 'candidates'])->name('substitutions.candidates');
            Route::post('substitutions', [TeacherScheduleController::class, 'storeSubstitution'])->name('substitutions.store');
            Route::delete('substitutions/{substitution}', [TeacherScheduleController::class, 'destroySubstitution'])->name('substitutions.destroy');
            Route::get('documents', [TeacherDocumentController::class, 'index'])->name('documents.index');
            Route::post('documents', [TeacherDocumentController::class, 'store'])->name('documents.store');
            Route::get('documents/{document}/file', [TeacherDocumentController::class, 'download'])->name('documents.download');
            Route::delete('documents/{document}', [TeacherDocumentController::class, 'destroy'])->name('documents.destroy');
        });
        Route::apiResource('holidays', HolidayController::class);
        Route::get('staff-attendance/sheet', [StaffAttendanceController::class, 'sheet'])->name('staff-attendance.sheet');
        Route::get('staff-attendance/report', [StaffAttendanceController::class, 'report'])->name('staff-attendance.report');
        Route::apiResource('overtime-requests', OvertimeRequestController::class)->only(['index', 'store', 'show', 'destroy']);
        Route::post('overtime-requests/{overtime_request}/approve', [OvertimeRequestController::class, 'approve'])->name('overtime-requests.approve');
        Route::post('overtime-requests/{overtime_request}/reject', [OvertimeRequestController::class, 'reject'])->name('overtime-requests.reject');
        Route::apiResource('attendance-corrections', AttendanceCorrectionController::class)->only(['index', 'store', 'show', 'destroy']);
        Route::post('attendance-corrections/{attendance_correction}/approve', [AttendanceCorrectionController::class, 'approve'])->name('attendance-corrections.approve');
        Route::post('attendance-corrections/{attendance_correction}/reject', [AttendanceCorrectionController::class, 'reject'])->name('attendance-corrections.reject');
        Route::apiResource('attendance-approvals', AttendanceApprovalController::class)->only(['index', 'store', 'destroy']);
        Route::post('staff-attendance/import', [StaffAttendanceController::class, 'import'])->name('staff-attendance.import');
        Route::apiResource('staff-attendance', StaffAttendanceController::class)->only(['index', 'store', 'update', 'destroy'])
            ->parameters(['staff-attendance' => 'staff_attendance']);

        // Student-submitted make-up class requests — see MakeUpClassRequestService.
        Route::apiResource('make-up-class-requests', MakeUpClassRequestController::class)->only(['index', 'show']);
        Route::post('make-up-class-requests/{make_up_class_request}/approve-to-study', [MakeUpClassRequestController::class, 'approveToStudy'])->name('make-up-class-requests.approve-to-study');
        Route::post('make-up-class-requests/{make_up_class_request}/approve', [MakeUpClassRequestController::class, 'approve'])->name('make-up-class-requests.approve');
        Route::post('make-up-class-requests/{make_up_class_request}/reject', [MakeUpClassRequestController::class, 'reject'])->name('make-up-class-requests.reject');

        // Students' requests to change class — see ClassChangeRequestService.
        Route::get('class-change-requests', [ClassChangeRequestController::class, 'index'])->name('class-change-requests.index');
        Route::post('class-change-requests/{classChangeRequest}/approve', [ClassChangeRequestController::class, 'approve'])->name('class-change-requests.approve');
        Route::post('class-change-requests/{classChangeRequest}/reject', [ClassChangeRequestController::class, 'reject'])->name('class-change-requests.reject');

        // Student-submitted requests/comments about the school or a
        // teacher, with a reply thread. See StudentFeedbackPolicy.
        Route::apiResource('student-feedback', StudentFeedbackController::class)->only(['index', 'show']);
        Route::post('student-feedback/{student_feedback}/reply', [StudentFeedbackController::class, 'storeReply'])->name('student-feedback.reply');

        // Student-submitted applications to sit an exam for one of their
        // own enrollments. See ExamApplicationPolicy.
        // Static-segment routes first — "lookup"/"sell"/"receive"/"pay-back"
        // would otherwise be swallowed by the apiResource's {exam_application}
        // route-model-bound "show"/"update"/"destroy" below.
        Route::get('exam-applications/lookup', [ExamApplicationController::class, 'lookup'])->name('exam-applications.lookup');
        Route::post('exam-applications/receive', [ExamApplicationController::class, 'receive'])->name('exam-applications.receive');
        Route::post('exam-applications/pay-back', [ExamApplicationController::class, 'payBack'])->name('exam-applications.pay-back');
        Route::post('exam-applications/photo-received', [ExamApplicationController::class, 'photoReceived'])->name('exam-applications.photo-received');
        Route::post('exam-applications/certificate-issued', [ExamApplicationController::class, 'certificateIssued'])->name('exam-applications.certificate-issued');
        Route::apiResource('exam-applications', ExamApplicationController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
        Route::post('exam-applications/{exam_application}/print', [ExamApplicationController::class, 'print'])->name('exam-applications.print');
        Route::post('exam-applications/{exam_application}/approve', [ExamApplicationController::class, 'approve'])->name('exam-applications.approve');
        Route::post('exam-applications/{exam_application}/reject', [ExamApplicationController::class, 'reject'])->name('exam-applications.reject');
        Route::post('exam-applications/{exam_application}/not-exam', [ExamApplicationController::class, 'markNotExam'])->name('exam-applications.not-exam');

        // Grades tab — scores against approved exam applications only, scoped
        // to the user's own classes unless they hold exam-scores.manage-all.
        // See ExamScoreService.
        Route::get('exam-scores/options', [ExamScoreController::class, 'options'])->name('exam-scores.options');
        Route::get('exam-scores', [ExamScoreController::class, 'index'])->name('exam-scores.index');
        Route::post('exam-scores', [ExamScoreController::class, 'store'])->name('exam-scores.store');

        // Approval Flow — groups of users that approval flows are built from.
        // See ApprovalGroupPolicy (approval-groups.manage).
        Route::get('approval-groups/users', [ApprovalGroupController::class, 'users'])->name('approval-groups.users');
        Route::apiResource('approval-groups', ApprovalGroupController::class);
        Route::get('approval-flows', [ApprovalFlowController::class, 'index'])->name('approval-flows.index');
        Route::put('approval-flows/{documentType}', [ApprovalFlowController::class, 'update'])->name('approval-flows.update');

        // eApprovals — generic form-template catalog + approval queue. See
        // ApprovalRequestPolicy's docblock: index on categories/templates is
        // unguarded (any authenticated user browses the catalog), only
        // managing it or the approve/reject queue is permission-gated.
        Route::apiResource('form-categories', FormCategoryController::class);
        Route::apiResource('form-templates', FormTemplateController::class);
        Route::apiResource('approval-requests', ApprovalRequestController::class)->only(['index', 'show']);
        Route::post('approval-requests/{approval_request}/approve', [ApprovalRequestController::class, 'approve'])->name('approval-requests.approve');
        Route::post('approval-requests/{approval_request}/reject', [ApprovalRequestController::class, 'reject'])->name('approval-requests.reject');

        // Project management — a Kanban board per project. Columns and tasks
        // ride on their parent project's own permission (see ProjectPolicy's
        // docblock), so they have no viewAny/index route of their own —
        // they're only ever reached nested under a project (see
        // ProjectController::show(), which eager-loads the whole board).
        // Before apiResource so "assignees" isn't swallowed by projects/{project}.
        Route::get('projects/assignees', [ProjectController::class, 'assignees'])->name('projects.assignees');
        Route::apiResource('projects', ProjectController::class);
        Route::post('projects/{project}/columns', [ProjectColumnController::class, 'store'])->name('projects.columns.store');
        Route::post('projects/{project}/columns/reorder', [ProjectColumnController::class, 'reorder'])->name('projects.columns.reorder');
        Route::put('project-columns/{project_column}', [ProjectColumnController::class, 'update'])->name('project-columns.update');
        Route::delete('project-columns/{project_column}', [ProjectColumnController::class, 'destroy'])->name('project-columns.destroy');
        Route::post('project-columns/{project_column}/tasks', [ProjectTaskController::class, 'store'])->name('project-columns.tasks.store');
        Route::put('project-tasks/{project_task}', [ProjectTaskController::class, 'update'])->name('project-tasks.update');
        Route::delete('project-tasks/{project_task}', [ProjectTaskController::class, 'destroy'])->name('project-tasks.destroy');
        Route::post('project-tasks/{project_task}/move', [ProjectTaskController::class, 'move'])->name('project-tasks.move');
        Route::get('project-tasks/{project_task}/history', [ProjectTaskController::class, 'history'])->name('project-tasks.history');
        Route::get('project-tasks/{project_task}/comments', [ProjectTaskCommentController::class, 'index'])->name('project-tasks.comments.index');
        Route::post('project-tasks/{project_task}/comments', [ProjectTaskCommentController::class, 'store'])->name('project-tasks.comments.store');
        Route::delete('project-task-comments/{project_task_comment}', [ProjectTaskCommentController::class, 'destroy'])->name('project-task-comments.destroy');

        // Sprint/Milestone — per-project, same lifecycle as columns.
        Route::post('projects/{project}/milestones', [ProjectMilestoneController::class, 'store'])->name('projects.milestones.store');
        Route::put('project-milestones/{project_milestone}', [ProjectMilestoneController::class, 'update'])->name('project-milestones.update');
        Route::delete('project-milestones/{project_milestone}', [ProjectMilestoneController::class, 'destroy'])->name('project-milestones.destroy');

        // Labels — a tenant-wide catalog shared by every project's cards, not nested under one project.
        Route::get('project-labels', [ProjectLabelController::class, 'index'])->name('project-labels.index');
        Route::post('project-labels', [ProjectLabelController::class, 'store'])->name('project-labels.store');
        Route::delete('project-labels/{project_label}', [ProjectLabelController::class, 'destroy'])->name('project-labels.destroy');

        // Checklist/Subtasks.
        Route::post('project-tasks/{project_task}/checklist-items', [ProjectTaskChecklistItemController::class, 'store'])->name('project-tasks.checklist-items.store');
        Route::put('project-task-checklist-items/{project_task_checklist_item}', [ProjectTaskChecklistItemController::class, 'update'])->name('project-task-checklist-items.update');
        Route::delete('project-task-checklist-items/{project_task_checklist_item}', [ProjectTaskChecklistItemController::class, 'destroy'])->name('project-task-checklist-items.destroy');

        // Attachments.
        Route::get('project-tasks/{project_task}/attachments', [ProjectTaskAttachmentController::class, 'index'])->name('project-tasks.attachments.index');
        Route::post('project-tasks/{project_task}/attachments', [ProjectTaskAttachmentController::class, 'store'])->name('project-tasks.attachments.store');
        Route::delete('project-tasks/{project_task}/attachments/{attachment}', [ProjectTaskAttachmentController::class, 'destroy'])->name('project-tasks.attachments.destroy');

        // Dependencies — "this card depends on that card."
        Route::post('project-tasks/{project_task}/dependencies', [ProjectTaskDependencyController::class, 'store'])->name('project-tasks.dependencies.store');
        Route::delete('project-tasks/{project_task}/dependencies/{depends_on_project_task}', [ProjectTaskDependencyController::class, 'destroy'])->name('project-tasks.dependencies.destroy');

        // The Upload menu — folders (two levels) of files for the public Download page.
        Route::apiResource('programs', AdminProgramController::class);

        // Position-based automatic user roles: Staff belongs to a Position,
        // a Position carries a Role — see UserProvisioningService and
        // StaffController::store()/update() for how the three tie together.
        Route::apiResource('positions', PositionController::class);

        // HRM > Organization Management's lists — one shared controller
        // (OrganizationUnitController), so each binds a plain {id} rather
        // than a per-model route-model-binding parameter.
        Route::apiResource('branches', BranchController::class)->parameters(['branches' => 'id']);
        Route::apiResource('teams', TeamController::class)->parameters(['teams' => 'id']);
        Route::apiResource('job-grades', JobGradeController::class)->parameters(['job-grades' => 'id']);
        Route::apiResource('job-levels', JobLevelController::class)->parameters(['job-levels' => 'id']);
        // The Reporting manager / Organization hierarchy tabs' one read.
        Route::get('organization/chart', OrganizationChartController::class)->name('organization.chart');

        Route::apiResource('staff', StaffController::class);
        Route::post('staff/{staff}/status', [StaffController::class, 'changeStatus'])->name('staff.status.update');

        // Read-only, cross-staff — see StaffStatusHistoryController's
        // docblock for why there is deliberately no store/update/destroy
        // route here.
        Route::get('staff-status-histories', [StaffStatusHistoryController::class, 'index'])->name('staff-status-histories.index');

        Route::apiResource('roles', RoleController::class)->except(['show']);

        // Removing an existing account is a separate, bigger "user
        // management" surface not yet built. `store` is how an
        // already-imported (never auto-provisioned) Student gets portal
        // access, or how an extra standalone account gets created; `update`
        // covers editing an existing account's profile fields and, for a
        // standalone (non-student-linked) account, reassigning its role.
        Route::apiResource('users', UserController::class)->only(['index', 'store', 'update']);
        Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::post('users/{user}/force-logout', [UserController::class, 'forceLogout'])->name('users.force-logout');

        // No store/update/destroy of a single entry — see AuditLogPolicy/
        // AuditLogController's docblocks. `clear` bulk-deletes a date range.
        Route::post('audit-logs/clear', [AuditLogController::class, 'clear'])->name('audit-logs.clear');
        Route::apiResource('audit-logs', AuditLogController::class)->only(['index', 'show']);

        // Super Admin: the central database or any active school's. With
        // database-backups.download: only the signed-in user's own school
        // (enforced in DatabaseBackupController). Streamed straight back.
        Route::get('database-backups', [DatabaseBackupController::class, 'index'])->name('database-backups.index');
        Route::get('database-backups/download', [DatabaseBackupController::class, 'download'])->name('database-backups.download');

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
        // A separate top-level path (not invoices/monthly) so it can never be
        // shadowed by the invoices/{invoice} route-model-bound route above.
        Route::get('monthly-invoices', [MonthlyInvoiceController::class, 'index'])->name('monthly-invoices.index');
        // The dashboard's "students due for monthly payment" widget.
        Route::get('monthly-payment-alerts', [MonthlyPaymentAlertController::class, 'index'])->name('monthly-payment-alerts.index');
        // The dashboard's "term students studying longer than N days" list.
        Route::get('term-study-alerts', [TermStudyAlertController::class, 'index'])->name('term-study-alerts.index');
        Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::post('invoices/{invoice}/stop', [InvoiceController::class, 'stop'])->name('invoices.stop');
        Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
        Route::get('invoices/{invoice}/image', [InvoiceController::class, 'downloadImage'])->name('invoices.image');
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

        Route::apiResource('currency-rates', CurrencyRateController::class);

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
        Route::get('accounting/dashboard/income', [AccountingDashboardController::class, 'income'])->name('accounting.dashboard.income');
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
        // Backs the payment-due popup on the public site.
        Route::get('my-monthly-payment-alerts', [MyMonthlyPaymentAlertController::class, 'index'])->name('my-monthly-payment-alerts.index');

        // Student self-service — identity-gated, same pattern as my-invoices.
        // Student self-service — identity-gated, same pattern as my-attendance.
        Route::get('my-scores', [MyExamScoreController::class, 'index'])->name('my-scores.index');
        // A student's own approved school results (Examination, docs/academics/gradebook.md 7d).
        Route::get('my-results', [StudentResultsController::class, 'mine'])->name('my-results.index');

        Route::get('my-attendance/hours-summary', [MyAttendanceController::class, 'hoursSummary'])->name('my-attendance.hours-summary');
        Route::get('my-attendance', [MyAttendanceController::class, 'index'])->name('my-attendance.index');

        // Student self-service — identity-gated, same pattern as my-attendance.
        Route::get('my-leave-requests/enrollments', [MyLeaveRequestController::class, 'enrollments'])->name('my-leave-requests.enrollments');
        Route::get('my-leave-requests/types', [MyLeaveRequestController::class, 'types'])->name('my-leave-requests.types');
        Route::get('my-leave-requests/quote', [MyLeaveRequestController::class, 'quote'])->name('my-leave-requests.quote');
        Route::get('my-leave-requests', [MyLeaveRequestController::class, 'index'])->name('my-leave-requests.index');
        Route::post('my-leave-requests', [MyLeaveRequestController::class, 'store'])->name('my-leave-requests.store');
        Route::delete('my-leave-requests/{leaveRequest}', [MyLeaveRequestController::class, 'destroy'])->whereNumber('leaveRequest')->name('my-leave-requests.destroy');

        // Staff self-service — identity-gated, same pattern as my-leave-requests.
        Route::get('my-resignation-requests/profile', [MyResignationRequestController::class, 'profile'])->name('my-resignation-requests.profile');
        Route::get('my-resignation-requests', [MyResignationRequestController::class, 'index'])->name('my-resignation-requests.index');
        Route::post('my-resignation-requests', [MyResignationRequestController::class, 'store'])->name('my-resignation-requests.store');

        // Student self-service — identity-gated, same pattern as my-leave-requests.
        Route::get('my-class-change-requests/options', [MyClassChangeRequestController::class, 'options'])->name('my-class-change-requests.options');
        Route::get('my-class-change-requests', [MyClassChangeRequestController::class, 'index'])->name('my-class-change-requests.index');
        Route::post('my-class-change-requests', [MyClassChangeRequestController::class, 'store'])->name('my-class-change-requests.store');
        Route::delete('my-class-change-requests/{classChangeRequest}', [MyClassChangeRequestController::class, 'destroy'])->whereNumber('classChangeRequest')->name('my-class-change-requests.destroy');
        Route::get('my-make-up-class-requests/enrollments',[MyMakeUpClassRequestController::class, 'enrollments'])->name('my-make-up-class-requests.enrollments');
        Route::get('my-make-up-class-requests', [MyMakeUpClassRequestController::class, 'index'])->name('my-make-up-class-requests.index');
        Route::post('my-make-up-class-requests', [MyMakeUpClassRequestController::class, 'store'])->name('my-make-up-class-requests.store');
        Route::delete('my-make-up-class-requests/{makeUpClassRequest}', [MyMakeUpClassRequestController::class, 'destroy'])->whereNumber('makeUpClassRequest')->name('my-make-up-class-requests.destroy');

        // Self-service — identity-gated, same pattern as my-leave-requests.
        Route::get('my-approval-requests', [MyApprovalRequestController::class, 'index'])->name('my-approval-requests.index');
        Route::post('my-approval-requests', [MyApprovalRequestController::class, 'store'])->name('my-approval-requests.store');
        Route::delete('my-approval-requests/{approvalRequest}', [MyApprovalRequestController::class, 'destroy'])->whereNumber('approvalRequest')->name('my-approval-requests.destroy');

        // Self-service — identity-gated, same pattern as my-leave-requests.
        Route::get('my-feedback/teachers', [MyStudentFeedbackController::class, 'teachers'])->name('my-feedback.teachers');
        Route::get('my-feedback', [MyStudentFeedbackController::class, 'index'])->name('my-feedback.index');
        Route::post('my-feedback', [MyStudentFeedbackController::class, 'store'])->name('my-feedback.store');
        Route::post('my-feedback/{student_feedback}/reply', [MyStudentFeedbackController::class, 'storeReply'])->name('my-feedback.reply');

        // Self-service — identity-gated, same pattern as my-leave-requests.
        Route::get('my-exam-applications/enrollments', [MyExamApplicationController::class, 'enrollments'])->name('my-exam-applications.enrollments');
        Route::get('my-exam-applications/lookup/{enrollment}', [MyExamApplicationController::class, 'lookup'])->name('my-exam-applications.lookup');
        Route::get('my-exam-applications/fee', [MyExamApplicationController::class, 'fee'])->name('my-exam-applications.fee');
        Route::get('my-exam-applications', [MyExamApplicationController::class, 'index'])->name('my-exam-applications.index');
        Route::post('my-exam-applications', [MyExamApplicationController::class, 'store'])->name('my-exam-applications.store');
        Route::post('my-exam-applications/{application}/qr', [MyExamApplicationController::class, 'qr'])->whereNumber('application')->name('my-exam-applications.qr');
        Route::get('my-exam-applications/{application}/payment', [MyExamApplicationController::class, 'payment'])->whereNumber('application')->name('my-exam-applications.payment');

        // Self-service — identity-gated (Staff/Student/User's own assignments), same pattern as my-invoices.
        Route::get('my-assets', [MyAssetController::class, 'index'])->name('my-assets.index');

        // Student self-service — identity-gated, same pattern as my-attendance.
        Route::get('my-videos', [MyVideoController::class, 'index'])->name('my-videos.index');

        // The admin header's notification bell — identity-gated (a user's own notifications), same pattern as my-leave-requests.
        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::post('notifications/mark-all-read', [NotificationController::class, 'markAllRead'])->name('notifications.mark-all-read');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // The school's own wording of the app's text — anyone signed in reads it
        // (the admin app applies it for everyone); saving needs
        // base-data.manage-translations. See TranslationOverrideController.
        Route::get('translation-overrides', [TranslationOverrideController::class, 'index'])->name('translation-overrides.index');
        Route::put('translation-overrides', [TranslationOverrideController::class, 'update'])->name('translation-overrides.update');

        // A staff member's own check-in / check-out — identity-gated, see
        // MyStaffAttendanceController (not the students' my-attendance above).
        Route::get('my-staff-attendance/today', [MyStaffAttendanceController::class, 'today'])->name('my-staff-attendance.today');

        // Staff self-service — identity-gated, see MyPerformanceReviewController.
        Route::get('my-performance-reviews', [MyPerformanceReviewController::class, 'mine'])->name('my-performance-reviews.index');
        Route::get('my-performance-reviews/{review}', [MyPerformanceReviewController::class, 'showMine'])->whereNumber('review')->name('my-performance-reviews.show');
        Route::put('my-performance-reviews/{review}', [MyPerformanceReviewController::class, 'saveMine'])->whereNumber('review')->name('my-performance-reviews.save');
        Route::post('my-performance-reviews/{review}/submit', [MyPerformanceReviewController::class, 'submitMine'])->whereNumber('review')->name('my-performance-reviews.submit');
        Route::get('team-performance-reviews', [MyPerformanceReviewController::class, 'team'])->name('team-performance-reviews.index');
        Route::get('team-performance-reviews/{review}', [MyPerformanceReviewController::class, 'showTeam'])->whereNumber('review')->name('team-performance-reviews.show');
        Route::put('team-performance-reviews/{review}', [MyPerformanceReviewController::class, 'saveTeam'])->whereNumber('review')->name('team-performance-reviews.save');
        Route::post('team-performance-reviews/{review}/submit', [MyPerformanceReviewController::class, 'submitTeam'])->whereNumber('review')->name('team-performance-reviews.submit');
        Route::get('my-staff-attendance', [MyStaffAttendanceController::class, 'index'])->name('my-staff-attendance.index');
        Route::post('my-staff-attendance/check-in', [MyStaffAttendanceController::class, 'checkIn'])->name('my-staff-attendance.check-in');
        Route::post('my-staff-attendance/check-out', [MyStaffAttendanceController::class, 'checkOut'])->name('my-staff-attendance.check-out');
        Route::get('my-overtime-requests', [MyOvertimeRequestController::class, 'index'])->name('my-overtime-requests.index');
        Route::post('my-overtime-requests', [MyOvertimeRequestController::class, 'store'])->name('my-overtime-requests.store');
        Route::get('my-attendance-corrections', [MyAttendanceCorrectionController::class, 'index'])->name('my-attendance-corrections.index');
        Route::post('my-attendance-corrections', [MyAttendanceCorrectionController::class, 'store'])->name('my-attendance-corrections.store');

        // Phone notifications (Web Push) for the current device — identity-gated, see MyPushSubscriptionController.
        Route::get('my-push-subscriptions/config', [MyPushSubscriptionController::class, 'config'])->name('my-push-subscriptions.config');
        Route::post('my-push-subscriptions', [MyPushSubscriptionController::class, 'store'])->name('my-push-subscriptions.store');
        Route::delete('my-push-subscriptions', [MyPushSubscriptionController::class, 'destroy'])->name('my-push-subscriptions.destroy');

        // Singleton, not a resource — see AboutPageController.

        // Same shape — see GeneralSettingsController. Currently just
        // `student_id_prefix`; a later general setting adds a key here
        // rather than a new endpoint.
        Route::get('settings/general', [GeneralSettingsController::class, 'show'])->name('settings.general.show');
        Route::post('settings/general', [GeneralSettingsController::class, 'update'])->name('settings.general.update');

        // Same shape — see SchoolSettingsController. Writes straight to the
        // tenants table's own name/email/phone/address/logo columns; this is
        // what the public site's header/footer branding reads back.
        Route::get('settings/school', [SchoolSettingsController::class, 'show'])->name('settings.school.show');

        // Telegram notification groups (docs/backoffice/stage-11-telegram-notifications.md).
        Route::prefix('settings/notification-groups')->name('settings.notification-groups.')->group(function () {
            Route::get('/', [NotificationGroupController::class, 'index'])->name('index');
            Route::get('users', [NotificationGroupController::class, 'users'])->name('users');
            Route::post('/', [NotificationGroupController::class, 'store'])->name('store');
            Route::put('{group}', [NotificationGroupController::class, 'update'])->whereNumber('group')->name('update');
            Route::delete('{group}', [NotificationGroupController::class, 'destroy'])->whereNumber('group')->name('destroy');
            Route::post('{group}/link-code', [NotificationGroupController::class, 'linkCode'])->whereNumber('group')->name('link-code');
            Route::post('{group}/check', [NotificationGroupController::class, 'check'])->whereNumber('group')->middleware('throttle:10,1')->name('check');
            Route::post('{group}/test', [NotificationGroupController::class, 'test'])->whereNumber('group')->middleware('throttle:10,1')->name('test');
            Route::post('{group}/unlink', [NotificationGroupController::class, 'unlink'])->whereNumber('group')->name('unlink');
        });
        Route::post('settings/school', [SchoolSettingsController::class, 'update'])->name('settings.school.update');

        // Singleton, not a resource — see SchoolDocumentsController.
        Route::get('settings/documents', [SchoolDocumentsController::class, 'show'])->name('settings.documents.show');
        Route::post('settings/documents', [SchoolDocumentsController::class, 'update'])->name('settings.documents.update');

        // Cambodia's administrative hierarchy — platform-wide reference data
        // for the student registration form's cascading address selects.
        Route::prefix('geo')->name('geo.')->group(function () {
            Route::get('provinces', [GeographyController::class, 'provinces'])->name('provinces');
            Route::get('districts', [GeographyController::class, 'districts'])->name('districts');
            Route::get('communes', [GeographyController::class, 'communes'])->name('communes');
            Route::get('villages', [GeographyController::class, 'villages'])->name('villages');
            Route::get('lookup', [GeographyController::class, 'lookup'])->name('lookup');
        });

        /*
        |----------------------------------------------------------------------
        | Base Data / Lookups — reusable multilingual dropdown catalog
        |----------------------------------------------------------------------
        |
        | The plain {id, code, name} dropdown-fetch endpoints are usable by
        | any signed-in tenant user — same precedent as the geo group above
        | (a teacher filling in a student's Gender needs this without a Base
        | Data admin permission). Only the admin management resources below
        | are permission-gated, via base-data.* policies.
        |
        */
        Route::prefix('lookups')->name('lookups.')->group(function () {
            Route::get('/', [LookupController::class, 'categories'])->name('categories');
            Route::get('{category}', [LookupController::class, 'values'])->name('values');
            Route::get('{category}/values', [LookupController::class, 'values'])->name('values.alias');
            Route::get('{category}/values/{id}', [LookupController::class, 'show'])->name('values.show');
        });

        Route::apiResource('languages', LanguageController::class);
        Route::apiResource('lookup-categories', LookupCategoryController::class);
        Route::apiResource('lookup-values', LookupValueController::class);
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
        // Always on: the console's sign-in page reads the school's name and
        // logo here, and says whether the website is on (website_enabled).
        Route::get('settings', [SiteSettingsController::class, 'show'])->name('settings');
        Route::post('student-registrations', [PublicStudentRegistrationController::class, 'store'])->name('student-registrations.store');

        // The school's public website: gone (404) when its plan has no
        // Website module (docs/backoffice/stage-5-billing.md, 5c).
        Route::middleware('school.module:website')->group(function () {
            Route::get('home-slides', [PublicHomeSlideController::class, 'index'])->name('home-slides.index');
            Route::get('gallery', [PublicGalleryController::class, 'index'])->name('gallery.index');
            Route::get('gallery/{id}/download', [PublicGalleryController::class, 'download'])
                ->whereNumber('id')
                ->name('gallery.download');
            Route::get('downloads', [PublicDownloadController::class, 'index'])->name('downloads.index');
            Route::get('downloads/folders/{id}', [PublicDownloadController::class, 'show'])->whereNumber('id')->name('downloads.folders.show');
            Route::get('downloads/files/{id}', [PublicDownloadController::class, 'download'])->whereNumber('id')->name('downloads.files.download');
            Route::get('downloads/folders/{id}/zip', [PublicDownloadController::class, 'zip'])
                ->whereNumber('id')
                ->middleware('throttle:download-zips')
                ->name('downloads.folders.zip');
            Route::get('promotions', [PublicPromotionController::class, 'index'])->name('promotions.index');
            // Careers: open jobs with an active Website posting (see JobPosition::scopeOnCareersPage()).
            Route::get('careers', [CareerController::class, 'index'])->name('careers.index');
            Route::get('careers/{id}', [CareerController::class, 'show'])->whereNumber('id')->name('careers.show');
            Route::post('careers/{id}/apply', [CareerController::class, 'apply'])->whereNumber('id')->middleware('throttle:job-applications')->name('careers.apply');
            Route::get('visits', [WebsiteVisitController::class, 'stats'])->name('visits.stats');
            Route::post('visits', [WebsiteVisitController::class, 'record'])->name('visits.record');
            Route::get('programs', [PublicProgramController::class, 'index'])->name('programs.index');
            Route::get('course-packages', [PublicCoursePackageController::class, 'index'])->name('course-packages.index');
            Route::get('course-packages/{course_package}/classes', [PublicCoursePackageController::class, 'classes'])->name('course-packages.classes');
            Route::get('video-lessons', [VideoLessonController::class, 'index'])->name('video-lessons.index');
            Route::get('schedules', [PublicScheduleController::class, 'index'])->name('schedules.index');
            Route::post('enrollment-inquiries', [EnrollmentInquiryController::class, 'store'])->name('enrollment-inquiries.store');
        });
        Route::get('khqr-preview', [PublicKhqrController::class, 'preview'])->name('khqr-preview');
        // NewTech's plans page (docs/backoffice/stage-8-signup.md, 8a); 404 on any other school.
        Route::get('plans', [PublicPlanController::class, 'index'])->name('plans.index');
        // Self-service sign-up (8b): web address check, start, and the visitor's own status page.
        Route::get('signup/organization-types', [PublicSignupController::class, 'organizationTypes'])->middleware('throttle:signup-checks')->name('signup.organization-types');
        Route::get('signup/slug', [PublicSignupController::class, 'slug'])->middleware('throttle:signup-checks')->name('signup.slug');
        Route::post('signup', [PublicSignupController::class, 'store'])->middleware('throttle:signups')->name('signup.store');
        Route::get('signup/{token}', [PublicSignupController::class, 'show'])->whereUuid('token')->middleware('throttle:signup-checks')->name('signup.show');
        // Try the demo (docs/backoffice/stage-12-demo.md): start one, or continue your own.
        Route::post('demo', [PublicDemoController::class, 'store'])->middleware('throttle:demos')->name('demo.store');
        Route::post('demo/{token}/enter', [PublicDemoController::class, 'resume'])->whereUuid('token')->middleware('throttle:signup-checks')->name('demo.resume');

        // Cambodia's administrative hierarchy, for the registration wizard's
        // cascading address selects — same platform-wide reference data and
        // controller the authenticated admin group below already uses.
        Route::prefix('geo')->name('geo.')->group(function () {
            Route::get('provinces', [GeographyController::class, 'provinces'])->name('provinces');
            Route::get('districts', [GeographyController::class, 'districts'])->name('districts');
            Route::get('communes', [GeographyController::class, 'communes'])->name('communes');
            Route::get('villages', [GeographyController::class, 'villages'])->name('villages');
        });
    });
});
