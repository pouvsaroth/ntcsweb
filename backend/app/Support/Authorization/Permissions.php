<?php

declare(strict_types=1);

namespace App\Support\Authorization;

/**
 * The permission catalog.
 *
 * This is the authoritative list — the seeder syncs the permissions table from
 * here, so adding a capability means adding it here and re-running the seeder,
 * never inserting a row by hand. Slugs are `<module>.<action>` and are used
 * verbatim in `$user->can(...)` and in policies.
 *
 * Modules are added as their phases land; only what exists today is listed, so
 * the catalog never advertises capabilities that nothing enforces.
 */
final class Permissions
{
    // Platform (super admin only in practice, but expressed as permissions so
    // a future "platform support" role can be given a read-only subset).
    public const TENANTS_VIEW = 'tenants.view';

    public const TENANTS_CREATE = 'tenants.create';

    public const TENANTS_UPDATE = 'tenants.update';

    public const TENANTS_DELETE = 'tenants.delete';

    public const TENANT_SETTINGS_VIEW = 'tenant-settings.view';

    public const TENANT_SETTINGS_UPDATE = 'tenant-settings.update';

    // User management, inside a school.
    public const USERS_VIEW = 'users.view';

    public const USERS_CREATE = 'users.create';

    public const USERS_UPDATE = 'users.update';

    public const USERS_DELETE = 'users.delete';

    // Roles and permissions.
    public const ROLES_VIEW = 'roles.view';

    public const ROLES_CREATE = 'roles.create';

    public const ROLES_UPDATE = 'roles.update';

    public const ROLES_DELETE = 'roles.delete';

    public const ROLES_ASSIGN = 'roles.assign';

    // Students.
    public const STUDENTS_VIEW = 'students.view';

    public const STUDENTS_CREATE = 'students.create';

    public const STUDENTS_UPDATE = 'students.update';

    public const STUDENTS_DELETE = 'students.delete';

    // Buildings — the physical buildings a school's classrooms belong to.
    public const BUILDINGS_VIEW = 'buildings.view';

    public const BUILDINGS_CREATE = 'buildings.create';

    public const BUILDINGS_UPDATE = 'buildings.update';

    public const BUILDINGS_DELETE = 'buildings.delete';

    // Classrooms.
    public const CLASSROOMS_VIEW = 'classrooms.view';

    public const CLASSROOMS_CREATE = 'classrooms.create';

    public const CLASSROOMS_UPDATE = 'classrooms.update';

    public const CLASSROOMS_DELETE = 'classrooms.delete';

    // Book Categories.
    public const BOOK_CATEGORIES_VIEW = 'book-categories.view';

    public const BOOK_CATEGORIES_CREATE = 'book-categories.create';

    public const BOOK_CATEGORIES_UPDATE = 'book-categories.update';

    public const BOOK_CATEGORIES_DELETE = 'book-categories.delete';

    // Books.
    public const BOOKS_VIEW = 'books.view';

    public const BOOKS_CREATE = 'books.create';

    public const BOOKS_UPDATE = 'books.update';

    public const BOOKS_DELETE = 'books.delete';

    // Classes — the scheduled teaching groups (name, teacher, room, the
    // study-day/study-time schedule, and the books used).
    public const CLASSES_VIEW = 'classes.view';

    public const CLASSES_CREATE = 'classes.create';

    public const CLASSES_UPDATE = 'classes.update';

    public const CLASSES_DELETE = 'classes.delete';

    // Enrollments — attaching/detaching students to/from a class.
    public const ENROLLMENTS_VIEW = 'enrollments.view';

    public const ENROLLMENTS_CREATE = 'enrollments.create';

    public const ENROLLMENTS_UPDATE = 'enrollments.update';

    public const ENROLLMENTS_DELETE = 'enrollments.delete';

    public const ENROLLMENTS_CANCEL = 'enrollments.cancel';

    public const ENROLLMENTS_TRANSFER = 'enrollments.transfer';

    // Study Modes — Full Time/Part Time and anything a school adds later.
    public const STUDY_MODES_VIEW = 'study-modes.view';

    public const STUDY_MODES_CREATE = 'study-modes.create';

    public const STUDY_MODES_UPDATE = 'study-modes.update';

    public const STUDY_MODES_DELETE = 'study-modes.delete';

    // Academic Programs — the school's own curriculum areas (English,
    // Chinese, Computer). Deliberately a different slug group from
    // `programs.*` above, which is the unrelated public marketing catalog.
    public const ACADEMIC_PROGRAMS_VIEW = 'academic-programs.view';

    public const ACADEMIC_PROGRAMS_CREATE = 'academic-programs.create';

    public const ACADEMIC_PROGRAMS_UPDATE = 'academic-programs.update';

    public const ACADEMIC_PROGRAMS_DELETE = 'academic-programs.delete';

    // Course Packages — the priced, purchasable registration item a student
    // actually pays for (e.g. "MS Word 2024" - $24), bundling several
    // Books (see Book::coursePackages()) — there is no separate "Course"
    // concept; a Book already is "a subject a student can take, with a
    // fee". Each package auto-owns a Product row (see CoursePackage's own
    // docblock), so no separate billing permission is needed here.
    public const COURSE_PACKAGES_VIEW = 'course-packages.view';

    public const COURSE_PACKAGES_CREATE = 'course-packages.create';

    public const COURSE_PACKAGES_UPDATE = 'course-packages.update';

    public const COURSE_PACKAGES_DELETE = 'course-packages.delete';

    // Academic Years — a real, tenant-owned school year (e.g. "2026").
    public const ACADEMIC_YEARS_VIEW = 'academic-years.view';

    public const ACADEMIC_YEARS_CREATE = 'academic-years.create';

    public const ACADEMIC_YEARS_UPDATE = 'academic-years.update';

    public const ACADEMIC_YEARS_DELETE = 'academic-years.delete';

    public const ACADEMIC_REPORTS_VIEW = 'academic-reports.view';

    public const ACADEMIC_REPORTS_EXPORT = 'academic-reports.export';

    // Base Data — the shared, multilingual lookup/dropdown catalog (Gender,
    // Guardian Type, Book Type, Payment Method, ...) every module reuses.
    // Reading a category's resolved values (what LookupSelect calls) is
    // deliberately NOT gated by any of these — any signed-in tenant user
    // needs Gender/etc. to fill in a form, same as Geography's province/
    // district/commune/village lookups. These four gate the admin
    // management screens only.
    public const BASE_DATA_VIEW = 'base-data.view';

    public const BASE_DATA_CREATE = 'base-data.create';

    public const BASE_DATA_UPDATE = 'base-data.update';

    public const BASE_DATA_DELETE = 'base-data.delete';

    public const BASE_DATA_MANAGE_TRANSLATIONS = 'base-data.manage-translations';

    public const BASE_DATA_MANAGE_LANGUAGES = 'base-data.manage-languages';

    // Home slides — the public homepage's image slider.
    public const HOME_SLIDES_VIEW = 'home-slides.view';

    public const HOME_SLIDES_CREATE = 'home-slides.create';

    public const HOME_SLIDES_UPDATE = 'home-slides.update';

    public const HOME_SLIDES_DELETE = 'home-slides.delete';

    // Gallery — photos on the public site's Gallery page.
    public const GALLERY_VIEW = 'gallery.view';

    public const GALLERY_CREATE = 'gallery.create';

    public const GALLERY_UPDATE = 'gallery.update';

    public const GALLERY_DELETE = 'gallery.delete';

    // Programs — the public marketing catalog of courses (not to be confused
    // with `classes`, an actual scheduled teaching group).
    public const PROGRAMS_VIEW = 'programs.view';

    public const PROGRAMS_CREATE = 'programs.create';

    public const PROGRAMS_UPDATE = 'programs.update';

    public const PROGRAMS_DELETE = 'programs.delete';

    // Positions — a job title carrying a Role, see Staff.
    public const POSITIONS_VIEW = 'positions.view';

    public const POSITIONS_CREATE = 'positions.create';

    public const POSITIONS_UPDATE = 'positions.update';

    public const POSITIONS_DELETE = 'positions.delete';

    // Staff — non-teaching personnel (Accountant, HR, Librarian, ...).
    public const STAFF_VIEW = 'staff.view';

    public const STAFF_CREATE = 'staff.create';

    public const STAFF_UPDATE = 'staff.update';

    public const STAFF_DELETE = 'staff.delete';

    // Products — the sellable catalog every invoice item bills against.
    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_CREATE = 'products.create';

    public const PRODUCTS_UPDATE = 'products.update';

    public const PRODUCTS_DELETE = 'products.delete';

    // Invoices/Payments/Receipts — financial records. Deliberately no
    // `invoices.delete`/`payments.delete`: a financial record is never
    // deleted, only cancelled/voided/refunded (see InvoiceService/
    // PaymentService), which `.cancel` covers on both.
    public const INVOICES_VIEW = 'invoices.view';

    public const INVOICES_CREATE = 'invoices.create';

    public const INVOICES_UPDATE = 'invoices.update';

    public const INVOICES_CANCEL = 'invoices.cancel';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_CREATE = 'payments.create';

    public const PAYMENTS_UPDATE = 'payments.update';

    public const PAYMENTS_CANCEL = 'payments.cancel';

    public const RECEIPTS_VIEW = 'receipts.view';

    public const BILLING_REPORTS_VIEW = 'billing-reports.view';

    public const NOTIFICATIONS_SEND = 'notifications.send';

    // Accounting — the Chart of Accounts and general ledger sitting on top
    // of Billing. Deliberately separate from the `billing.*`-flavored
    // permissions above: a school may want billing staff who can't touch
    // the Chart of Accounts, or an accountant with no invoice-editing
    // rights, via the existing Position/Role permission-matrix editor.
    public const ACCOUNTING_VIEW = 'accounting.view';

    public const ACCOUNTING_DASHBOARD_VIEW = 'accounting.dashboard.view';

    public const ACCOUNTS_VIEW = 'accounts.view';

    public const ACCOUNTS_CREATE = 'accounts.create';

    public const ACCOUNTS_UPDATE = 'accounts.update';

    public const ACCOUNTS_DEACTIVATE = 'accounts.deactivate';

    public const INCOME_VIEW = 'income.view';

    public const INCOME_CREATE = 'income.create';

    public const INCOME_UPDATE = 'income.update';

    public const INCOME_CANCEL = 'income.cancel';

    public const EXPENSE_VIEW = 'expense.view';

    public const EXPENSE_CREATE = 'expense.create';

    public const EXPENSE_UPDATE = 'expense.update';

    public const EXPENSE_APPROVE = 'expense.approve';

    public const EXPENSE_REJECT = 'expense.reject';

    public const EXPENSE_CANCEL = 'expense.cancel';

    public const EXPENSE_PAY = 'expense.pay';

    public const TRANSACTIONS_VIEW = 'transactions.view';

    public const TRANSACTIONS_CREATE = 'transactions.create';

    public const REPORTS_FINANCIAL_VIEW = 'reports.financial.view';

    public const REPORTS_FINANCIAL_EXPORT = 'reports.financial.export';

    public const ACCOUNTING_PERIOD_CLOSE = 'accounting.period.close';

    public const ACCOUNTING_ADJUSTMENT_CREATE = 'accounting.adjustment.create';

    // Attendance — taking/viewing a class's daily roll call. No `.delete`:
    // a taken record is corrected via `.update`, never removed, so a day's
    // roll call can't quietly disappear from a student's history.
    public const ATTENDANCE_VIEW = 'attendance.view';

    public const ATTENDANCE_CREATE = 'attendance.create';

    public const ATTENDANCE_UPDATE = 'attendance.update';

    // Assets — physical property lifecycle management, sitting alongside but
    // separate from Accounting: an IT Officer can hold every assets.*
    // permission without touching the Chart of Accounts, and an Accountant
    // can approve/pay the Expense a repair generates without holding any
    // assets.* permission at all, via the existing Position/Role editor.
    public const ASSETS_VIEW = 'assets.view';

    public const ASSETS_CREATE = 'assets.create';

    public const ASSETS_UPDATE = 'assets.update';

    public const ASSETS_DELETE = 'assets.delete';

    public const ASSETS_ASSIGN = 'assets.assign';

    public const ASSETS_RETURN = 'assets.return';

    public const ASSETS_TRANSFER = 'assets.transfer';

    public const ASSETS_RETIRE = 'assets.retire';

    public const ASSETS_DISPOSE = 'assets.dispose';

    public const ASSETS_MARK_LOST = 'assets.mark_lost';

    public const ASSETS_MARK_FOUND = 'assets.mark_found';

    public const ASSET_ISSUES_VIEW = 'assets.issue.view';

    public const ASSET_ISSUES_CREATE = 'assets.issue.create';

    public const ASSET_ISSUES_UPDATE = 'assets.issue.update';

    public const ASSET_ISSUES_RESOLVE = 'assets.issue.resolve';

    public const ASSET_REPAIRS_VIEW = 'assets.repair.view';

    public const ASSET_REPAIRS_CREATE = 'assets.repair.create';

    public const ASSET_REPAIRS_UPDATE = 'assets.repair.update';

    public const ASSET_REPAIRS_COMPLETE = 'assets.repair.complete';

    public const ASSET_MAINTENANCE_VIEW = 'assets.maintenance.view';

    public const ASSET_MAINTENANCE_CREATE = 'assets.maintenance.create';

    public const ASSET_MAINTENANCE_UPDATE = 'assets.maintenance.update';

    public const ASSET_REPORTS_VIEW = 'assets.reports.view';

    public const ASSET_REPORTS_EXPORT = 'assets.reports.export';

    // System.
    public const AUDIT_LOGS_VIEW = 'audit-logs.view';

    /**
     * Every permission, grouped for the admin UI checkbox list.
     *
     * @return array<string, array<string, string>> group => [slug => label]
     */
    public static function catalog(): array
    {
        return [
            'Platform' => [
                self::TENANTS_VIEW => 'View schools',
                self::TENANTS_CREATE => 'Create schools',
                self::TENANTS_UPDATE => 'Update schools',
                self::TENANTS_DELETE => 'Delete schools',
            ],
            'School settings' => [
                self::TENANT_SETTINGS_VIEW => 'View school settings',
                self::TENANT_SETTINGS_UPDATE => 'Update school settings',
            ],
            'Users' => [
                self::USERS_VIEW => 'View users',
                self::USERS_CREATE => 'Create users',
                self::USERS_UPDATE => 'Update users',
                self::USERS_DELETE => 'Delete users',
            ],
            'Roles' => [
                self::ROLES_VIEW => 'View roles',
                self::ROLES_CREATE => 'Create roles',
                self::ROLES_UPDATE => 'Update roles',
                self::ROLES_DELETE => 'Delete roles',
                self::ROLES_ASSIGN => 'Assign roles to users',
            ],
            'Students' => [
                self::STUDENTS_VIEW => 'View students',
                self::STUDENTS_CREATE => 'Create students',
                self::STUDENTS_UPDATE => 'Update students',
                self::STUDENTS_DELETE => 'Delete students',
            ],
            'Buildings' => [
                self::BUILDINGS_VIEW => 'View buildings',
                self::BUILDINGS_CREATE => 'Create buildings',
                self::BUILDINGS_UPDATE => 'Update buildings',
                self::BUILDINGS_DELETE => 'Delete buildings',
            ],
            'Classrooms' => [
                self::CLASSROOMS_VIEW => 'View classrooms',
                self::CLASSROOMS_CREATE => 'Create classrooms',
                self::CLASSROOMS_UPDATE => 'Update classrooms',
                self::CLASSROOMS_DELETE => 'Delete classrooms',
            ],
            'Book Categories' => [
                self::BOOK_CATEGORIES_VIEW => 'View book categories',
                self::BOOK_CATEGORIES_CREATE => 'Create book categories',
                self::BOOK_CATEGORIES_UPDATE => 'Update book categories',
                self::BOOK_CATEGORIES_DELETE => 'Delete book categories',
            ],
            'Books' => [
                self::BOOKS_VIEW => 'View books',
                self::BOOKS_CREATE => 'Create books',
                self::BOOKS_UPDATE => 'Update books',
                self::BOOKS_DELETE => 'Delete books',
            ],
            'Classes' => [
                self::CLASSES_VIEW => 'View classes',
                self::CLASSES_CREATE => 'Create classes',
                self::CLASSES_UPDATE => 'Update classes',
                self::CLASSES_DELETE => 'Delete classes',
            ],
            'Enrollments' => [
                self::ENROLLMENTS_VIEW => 'View enrollments',
                self::ENROLLMENTS_CREATE => 'Create enrollments',
                self::ENROLLMENTS_UPDATE => 'Update enrollments',
                self::ENROLLMENTS_DELETE => 'Delete enrollments',
                self::ENROLLMENTS_CANCEL => 'Cancel enrollments',
                self::ENROLLMENTS_TRANSFER => 'Transfer a student to another class',
            ],
            'Study Modes' => [
                self::STUDY_MODES_VIEW => 'View study modes',
                self::STUDY_MODES_CREATE => 'Create study modes',
                self::STUDY_MODES_UPDATE => 'Update study modes',
                self::STUDY_MODES_DELETE => 'Delete study modes',
            ],
            'Academic Programs' => [
                self::ACADEMIC_PROGRAMS_VIEW => 'View academic programs',
                self::ACADEMIC_PROGRAMS_CREATE => 'Create academic programs',
                self::ACADEMIC_PROGRAMS_UPDATE => 'Update academic programs',
                self::ACADEMIC_PROGRAMS_DELETE => 'Delete academic programs',
            ],
            'Course Packages' => [
                self::COURSE_PACKAGES_VIEW => 'View course packages',
                self::COURSE_PACKAGES_CREATE => 'Create course packages',
                self::COURSE_PACKAGES_UPDATE => 'Update course packages',
                self::COURSE_PACKAGES_DELETE => 'Delete course packages',
            ],
            'Academic Years' => [
                self::ACADEMIC_YEARS_VIEW => 'View academic years',
                self::ACADEMIC_YEARS_CREATE => 'Create academic years',
                self::ACADEMIC_YEARS_UPDATE => 'Update academic years',
                self::ACADEMIC_YEARS_DELETE => 'Delete academic years',
            ],
            'Academic Reports' => [
                self::ACADEMIC_REPORTS_VIEW => 'View academic/enrollment reports',
                self::ACADEMIC_REPORTS_EXPORT => 'Export academic/enrollment reports',
            ],
            'Base Data' => [
                self::BASE_DATA_VIEW => 'View base data (lookup categories & values)',
                self::BASE_DATA_CREATE => 'Create lookup categories & values',
                self::BASE_DATA_UPDATE => 'Update lookup categories & values',
                self::BASE_DATA_DELETE => 'Delete lookup categories & values',
                self::BASE_DATA_MANAGE_TRANSLATIONS => 'Manage lookup value translations',
                self::BASE_DATA_MANAGE_LANGUAGES => 'Manage supported languages',
            ],
            'Home slides' => [
                self::HOME_SLIDES_VIEW => 'View homepage slides',
                self::HOME_SLIDES_CREATE => 'Upload homepage slides',
                self::HOME_SLIDES_UPDATE => 'Update homepage slides',
                self::HOME_SLIDES_DELETE => 'Delete homepage slides',
            ],
            'Gallery' => [
                self::GALLERY_VIEW => 'View gallery photos',
                self::GALLERY_CREATE => 'Upload gallery photos',
                self::GALLERY_UPDATE => 'Update gallery photos',
                self::GALLERY_DELETE => 'Delete gallery photos',
            ],
            'Programs' => [
                self::PROGRAMS_VIEW => 'View programs',
                self::PROGRAMS_CREATE => 'Create programs',
                self::PROGRAMS_UPDATE => 'Update programs',
                self::PROGRAMS_DELETE => 'Delete programs',
            ],
            'Positions' => [
                self::POSITIONS_VIEW => 'View positions',
                self::POSITIONS_CREATE => 'Create positions',
                self::POSITIONS_UPDATE => 'Update positions',
                self::POSITIONS_DELETE => 'Delete positions',
            ],
            'Staff' => [
                self::STAFF_VIEW => 'View staff',
                self::STAFF_CREATE => 'Create staff',
                self::STAFF_UPDATE => 'Update staff',
                self::STAFF_DELETE => 'Delete staff',
            ],
            'Products' => [
                self::PRODUCTS_VIEW => 'View products',
                self::PRODUCTS_CREATE => 'Create products',
                self::PRODUCTS_UPDATE => 'Update products',
                self::PRODUCTS_DELETE => 'Delete products',
            ],
            'Billing' => [
                self::INVOICES_VIEW => 'View invoices',
                self::INVOICES_CREATE => 'Create invoices',
                self::INVOICES_UPDATE => 'Update invoices',
                self::INVOICES_CANCEL => 'Cancel or void invoices',
                self::PAYMENTS_VIEW => 'View payments',
                self::PAYMENTS_CREATE => 'Record payments',
                self::PAYMENTS_UPDATE => 'Update payments',
                self::PAYMENTS_CANCEL => 'Cancel or refund payments',
                self::RECEIPTS_VIEW => 'View receipts',
                self::BILLING_REPORTS_VIEW => 'View billing reports and dashboard',
                self::NOTIFICATIONS_SEND => 'Send invoice notifications',
            ],
            'Accounting' => [
                self::ACCOUNTING_VIEW => 'View the Accounting module',
                self::ACCOUNTING_DASHBOARD_VIEW => 'View the Accounting dashboard',
                self::ACCOUNTS_VIEW => 'View the Chart of Accounts',
                self::ACCOUNTS_CREATE => 'Create accounts',
                self::ACCOUNTS_UPDATE => 'Update accounts',
                self::ACCOUNTS_DEACTIVATE => 'Deactivate accounts',
                self::INCOME_VIEW => 'View income entries',
                self::INCOME_CREATE => 'Record manual income',
                self::INCOME_UPDATE => 'Update income entries',
                self::INCOME_CANCEL => 'Cancel income entries',
                self::EXPENSE_VIEW => 'View expenses',
                self::EXPENSE_CREATE => 'Create expenses',
                self::EXPENSE_UPDATE => 'Update expenses',
                self::EXPENSE_APPROVE => 'Approve expenses',
                self::EXPENSE_REJECT => 'Reject expenses',
                self::EXPENSE_CANCEL => 'Cancel expenses',
                self::EXPENSE_PAY => 'Pay expenses',
                self::TRANSACTIONS_VIEW => 'View the general ledger',
                self::TRANSACTIONS_CREATE => 'Create transfers and adjustments',
                self::REPORTS_FINANCIAL_VIEW => 'View financial reports',
                self::REPORTS_FINANCIAL_EXPORT => 'Export financial reports',
                self::ACCOUNTING_PERIOD_CLOSE => 'Close accounting periods',
                self::ACCOUNTING_ADJUSTMENT_CREATE => 'Create manual adjustments',
            ],
            'Attendance' => [
                self::ATTENDANCE_VIEW => 'View attendance records',
                self::ATTENDANCE_CREATE => 'Take attendance',
                self::ATTENDANCE_UPDATE => 'Correct attendance records',
            ],
            'Assets' => [
                self::ASSETS_VIEW => 'View assets',
                self::ASSETS_CREATE => 'Create assets',
                self::ASSETS_UPDATE => 'Update assets',
                self::ASSETS_DELETE => 'Delete assets',
                self::ASSETS_ASSIGN => 'Assign assets',
                self::ASSETS_RETURN => 'Return assigned assets',
                self::ASSETS_TRANSFER => 'Transfer assets between locations/departments',
                self::ASSETS_RETIRE => 'Retire assets',
                self::ASSETS_DISPOSE => 'Dispose of assets',
                self::ASSETS_MARK_LOST => 'Mark assets as lost/missing',
                self::ASSETS_MARK_FOUND => 'Mark lost/missing assets as found',
                self::ASSET_ISSUES_VIEW => 'View reported asset issues',
                self::ASSET_ISSUES_CREATE => 'Report asset issues',
                self::ASSET_ISSUES_UPDATE => 'Update asset issues',
                self::ASSET_ISSUES_RESOLVE => 'Resolve asset issues',
                self::ASSET_REPAIRS_VIEW => 'View asset repairs',
                self::ASSET_REPAIRS_CREATE => 'Send assets to repair',
                self::ASSET_REPAIRS_UPDATE => 'Update asset repairs',
                self::ASSET_REPAIRS_COMPLETE => 'Complete asset repairs',
                self::ASSET_MAINTENANCE_VIEW => 'View asset maintenance',
                self::ASSET_MAINTENANCE_CREATE => 'Schedule asset maintenance',
                self::ASSET_MAINTENANCE_UPDATE => 'Update/complete asset maintenance',
                self::ASSET_REPORTS_VIEW => 'View asset reports and dashboard',
                self::ASSET_REPORTS_EXPORT => 'Export asset reports',
            ],
            'System' => [
                self::AUDIT_LOGS_VIEW => 'View audit logs',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return array_merge(...array_map(array_keys(...), array_values(self::catalog())));
    }

    /**
     * Default permission sets for the system roles seeded into every school.
     *
     * super-admin is absent on purpose: it is granted unconditionally by
     * Gate::before rather than by holding every row, so a newly added
     * permission never needs a backfill to reach it.
     *
     * @return array<string, list<string>>
     */
    public static function defaultsForSystemRoles(): array
    {
        $academicManagement = [
            self::STUDENTS_VIEW, self::STUDENTS_CREATE, self::STUDENTS_UPDATE, self::STUDENTS_DELETE,
            self::BUILDINGS_VIEW, self::BUILDINGS_CREATE, self::BUILDINGS_UPDATE, self::BUILDINGS_DELETE,
            self::CLASSROOMS_VIEW, self::CLASSROOMS_CREATE, self::CLASSROOMS_UPDATE, self::CLASSROOMS_DELETE,
            self::BOOK_CATEGORIES_VIEW, self::BOOK_CATEGORIES_CREATE, self::BOOK_CATEGORIES_UPDATE, self::BOOK_CATEGORIES_DELETE,
            self::BOOKS_VIEW, self::BOOKS_CREATE, self::BOOKS_UPDATE, self::BOOKS_DELETE,
            self::CLASSES_VIEW, self::CLASSES_CREATE, self::CLASSES_UPDATE, self::CLASSES_DELETE,
            self::ENROLLMENTS_VIEW, self::ENROLLMENTS_CREATE, self::ENROLLMENTS_UPDATE, self::ENROLLMENTS_DELETE,
            self::ENROLLMENTS_CANCEL, self::ENROLLMENTS_TRANSFER,
            self::STUDY_MODES_VIEW, self::STUDY_MODES_CREATE, self::STUDY_MODES_UPDATE, self::STUDY_MODES_DELETE,
            self::ACADEMIC_PROGRAMS_VIEW, self::ACADEMIC_PROGRAMS_CREATE, self::ACADEMIC_PROGRAMS_UPDATE, self::ACADEMIC_PROGRAMS_DELETE,
            self::COURSE_PACKAGES_VIEW, self::COURSE_PACKAGES_CREATE, self::COURSE_PACKAGES_UPDATE, self::COURSE_PACKAGES_DELETE,
            self::ACADEMIC_YEARS_VIEW, self::ACADEMIC_YEARS_CREATE, self::ACADEMIC_YEARS_UPDATE, self::ACADEMIC_YEARS_DELETE,
            self::ACADEMIC_REPORTS_VIEW, self::ACADEMIC_REPORTS_EXPORT,
            self::HOME_SLIDES_VIEW, self::HOME_SLIDES_CREATE, self::HOME_SLIDES_UPDATE, self::HOME_SLIDES_DELETE,
            self::GALLERY_VIEW, self::GALLERY_CREATE, self::GALLERY_UPDATE, self::GALLERY_DELETE,
            self::PROGRAMS_VIEW, self::PROGRAMS_CREATE, self::PROGRAMS_UPDATE, self::PROGRAMS_DELETE,
            self::POSITIONS_VIEW, self::POSITIONS_CREATE, self::POSITIONS_UPDATE, self::POSITIONS_DELETE,
            self::STAFF_VIEW, self::STAFF_CREATE, self::STAFF_UPDATE, self::STAFF_DELETE,
        ];

        // Not folded into $academicManagement: financial access is
        // deliberately its own, tighter group — school-admin only by
        // default (see the class docblock's example: "not all users access
        // financial functions"). A school that wants an Accountant role
        // creates one via the existing Position/Role UI and grants these
        // through the permission-matrix editor, the same way any other
        // custom role is built — no hardcoded "Accountant" role needed here.
        $billing = [
            self::PRODUCTS_VIEW, self::PRODUCTS_CREATE, self::PRODUCTS_UPDATE, self::PRODUCTS_DELETE,
            self::INVOICES_VIEW, self::INVOICES_CREATE, self::INVOICES_UPDATE, self::INVOICES_CANCEL,
            self::PAYMENTS_VIEW, self::PAYMENTS_CREATE, self::PAYMENTS_UPDATE, self::PAYMENTS_CANCEL,
            self::RECEIPTS_VIEW, self::BILLING_REPORTS_VIEW, self::NOTIFICATIONS_SEND,
        ];

        // Same reasoning as $billing above — its own tight group,
        // school-admin only by default.
        $accounting = [
            self::ACCOUNTING_VIEW, self::ACCOUNTING_DASHBOARD_VIEW,
            self::ACCOUNTS_VIEW, self::ACCOUNTS_CREATE, self::ACCOUNTS_UPDATE, self::ACCOUNTS_DEACTIVATE,
            self::INCOME_VIEW, self::INCOME_CREATE, self::INCOME_UPDATE, self::INCOME_CANCEL,
            self::EXPENSE_VIEW, self::EXPENSE_CREATE, self::EXPENSE_UPDATE,
            self::EXPENSE_APPROVE, self::EXPENSE_REJECT, self::EXPENSE_CANCEL, self::EXPENSE_PAY,
            self::TRANSACTIONS_VIEW, self::TRANSACTIONS_CREATE,
            self::REPORTS_FINANCIAL_VIEW, self::REPORTS_FINANCIAL_EXPORT,
            self::ACCOUNTING_PERIOD_CLOSE, self::ACCOUNTING_ADJUSTMENT_CREATE,
        ];

        // Same reasoning as $billing/$accounting above — its own tight
        // group, school-admin only by default. A school that wants a
        // dedicated "IT Officer" role grants a subset of these through the
        // existing Position/Role permission-matrix editor.
        $assets = [
            self::ASSETS_VIEW, self::ASSETS_CREATE, self::ASSETS_UPDATE, self::ASSETS_DELETE,
            self::ASSETS_ASSIGN, self::ASSETS_RETURN, self::ASSETS_TRANSFER,
            self::ASSETS_RETIRE, self::ASSETS_DISPOSE, self::ASSETS_MARK_LOST, self::ASSETS_MARK_FOUND,
            self::ASSET_ISSUES_VIEW, self::ASSET_ISSUES_CREATE, self::ASSET_ISSUES_UPDATE, self::ASSET_ISSUES_RESOLVE,
            self::ASSET_REPAIRS_VIEW, self::ASSET_REPAIRS_CREATE, self::ASSET_REPAIRS_UPDATE, self::ASSET_REPAIRS_COMPLETE,
            self::ASSET_MAINTENANCE_VIEW, self::ASSET_MAINTENANCE_CREATE, self::ASSET_MAINTENANCE_UPDATE,
            self::ASSET_REPORTS_VIEW, self::ASSET_REPORTS_EXPORT,
        ];

        return [
            \App\Models\Role::SCHOOL_ADMIN => [
                self::TENANT_SETTINGS_VIEW,
                self::TENANT_SETTINGS_UPDATE,
                self::USERS_VIEW,
                self::USERS_CREATE,
                self::USERS_UPDATE,
                self::USERS_DELETE,
                self::ROLES_VIEW,
                self::ROLES_CREATE,
                self::ROLES_UPDATE,
                self::ROLES_DELETE,
                self::ROLES_ASSIGN,
                self::AUDIT_LOGS_VIEW,
                self::BASE_DATA_VIEW,
                self::BASE_DATA_CREATE,
                self::BASE_DATA_UPDATE,
                self::BASE_DATA_DELETE,
                self::BASE_DATA_MANAGE_TRANSLATIONS,
                self::BASE_DATA_MANAGE_LANGUAGES,
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_CREATE,
                self::ATTENDANCE_UPDATE,
                ...$academicManagement,
                ...$billing,
                ...$accounting,
                ...$assets,
            ],
            // Read-only across the board, with one write exception: teaching
            // records (who teaches what, in which room) stay a school-admin
            // decision, but attendance is a teacher's own daily task —
            // AttendancePolicy further restricts create/update to the
            // classes a teacher account is actually assigned to teach.
            \App\Models\Role::TEACHER => [
                self::USERS_VIEW,
                self::STUDENTS_VIEW,
                self::BUILDINGS_VIEW,
                self::CLASSROOMS_VIEW,
                self::BOOK_CATEGORIES_VIEW,
                self::BOOKS_VIEW,
                self::CLASSES_VIEW,
                self::ENROLLMENTS_VIEW,
                self::STUDY_MODES_VIEW,
                self::ACADEMIC_PROGRAMS_VIEW,
                self::COURSE_PACKAGES_VIEW,
                self::ACADEMIC_YEARS_VIEW,
                self::POSITIONS_VIEW,
                self::STAFF_VIEW,
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_CREATE,
                self::ATTENDANCE_UPDATE,
            ],
            // Staff commonly handle front-desk registration, so they can
            // create/update students and enrollments (including the new
            // package-based enrollment path, which needs read access to the
            // whole Program/Study Mode/Course Package catalog to build one),
            // but not delete them (deletion stays a school-admin action) and
            // can't touch the teaching catalog (classrooms/books/classes/
            // academic programs/packages) itself.
            \App\Models\Role::STAFF => [
                self::USERS_VIEW,
                self::POSITIONS_VIEW,
                self::STAFF_VIEW,
                self::STUDENTS_VIEW,
                self::STUDENTS_CREATE,
                self::STUDENTS_UPDATE,
                self::BUILDINGS_VIEW,
                self::CLASSROOMS_VIEW,
                self::BOOK_CATEGORIES_VIEW,
                self::BOOKS_VIEW,
                self::CLASSES_VIEW,
                self::ENROLLMENTS_VIEW,
                self::ENROLLMENTS_CREATE,
                self::ENROLLMENTS_UPDATE,
                self::ENROLLMENTS_TRANSFER,
                self::STUDY_MODES_VIEW,
                self::ACADEMIC_PROGRAMS_VIEW,
                self::COURSE_PACKAGES_VIEW,
                self::ACADEMIC_YEARS_VIEW,
            ],
            \App\Models\Role::STUDENT => [],
        ];
    }
}
