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

    // Teachers.
    public const TEACHERS_VIEW = 'teachers.view';

    public const TEACHERS_CREATE = 'teachers.create';

    public const TEACHERS_UPDATE = 'teachers.update';

    public const TEACHERS_DELETE = 'teachers.delete';

    // Students.
    public const STUDENTS_VIEW = 'students.view';

    public const STUDENTS_CREATE = 'students.create';

    public const STUDENTS_UPDATE = 'students.update';

    public const STUDENTS_DELETE = 'students.delete';

    // Classrooms.
    public const CLASSROOMS_VIEW = 'classrooms.view';

    public const CLASSROOMS_CREATE = 'classrooms.create';

    public const CLASSROOMS_UPDATE = 'classrooms.update';

    public const CLASSROOMS_DELETE = 'classrooms.delete';

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
            'Teachers' => [
                self::TEACHERS_VIEW => 'View teachers',
                self::TEACHERS_CREATE => 'Create teachers',
                self::TEACHERS_UPDATE => 'Update teachers',
                self::TEACHERS_DELETE => 'Delete teachers',
            ],
            'Students' => [
                self::STUDENTS_VIEW => 'View students',
                self::STUDENTS_CREATE => 'Create students',
                self::STUDENTS_UPDATE => 'Update students',
                self::STUDENTS_DELETE => 'Delete students',
            ],
            'Classrooms' => [
                self::CLASSROOMS_VIEW => 'View classrooms',
                self::CLASSROOMS_CREATE => 'Create classrooms',
                self::CLASSROOMS_UPDATE => 'Update classrooms',
                self::CLASSROOMS_DELETE => 'Delete classrooms',
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
            self::TEACHERS_VIEW, self::TEACHERS_CREATE, self::TEACHERS_UPDATE, self::TEACHERS_DELETE,
            self::STUDENTS_VIEW, self::STUDENTS_CREATE, self::STUDENTS_UPDATE, self::STUDENTS_DELETE,
            self::CLASSROOMS_VIEW, self::CLASSROOMS_CREATE, self::CLASSROOMS_UPDATE, self::CLASSROOMS_DELETE,
            self::BOOKS_VIEW, self::BOOKS_CREATE, self::BOOKS_UPDATE, self::BOOKS_DELETE,
            self::CLASSES_VIEW, self::CLASSES_CREATE, self::CLASSES_UPDATE, self::CLASSES_DELETE,
            self::ENROLLMENTS_VIEW, self::ENROLLMENTS_CREATE, self::ENROLLMENTS_UPDATE, self::ENROLLMENTS_DELETE,
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
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_CREATE,
                self::ATTENDANCE_UPDATE,
                ...$academicManagement,
                ...$billing,
                ...$accounting,
            ],
            // Read-only across the board, with one write exception: teaching
            // records (who teaches what, in which room) stay a school-admin
            // decision, but attendance is a teacher's own daily task —
            // AttendancePolicy further restricts create/update to the
            // classes a teacher account is actually assigned to teach.
            \App\Models\Role::TEACHER => [
                self::USERS_VIEW,
                self::TEACHERS_VIEW,
                self::STUDENTS_VIEW,
                self::CLASSROOMS_VIEW,
                self::BOOKS_VIEW,
                self::CLASSES_VIEW,
                self::ENROLLMENTS_VIEW,
                self::POSITIONS_VIEW,
                self::STAFF_VIEW,
                self::ATTENDANCE_VIEW,
                self::ATTENDANCE_CREATE,
                self::ATTENDANCE_UPDATE,
            ],
            // Staff commonly handle front-desk registration, so they can
            // create/update students and enrollments, but not delete them
            // (deletion stays a school-admin action) and can't touch the
            // teaching catalog (teachers/classrooms/books/classes) itself.
            \App\Models\Role::STAFF => [
                self::USERS_VIEW,
                self::POSITIONS_VIEW,
                self::STAFF_VIEW,
                self::TEACHERS_VIEW,
                self::STUDENTS_VIEW,
                self::STUDENTS_CREATE,
                self::STUDENTS_UPDATE,
                self::CLASSROOMS_VIEW,
                self::BOOKS_VIEW,
                self::CLASSES_VIEW,
                self::ENROLLMENTS_VIEW,
                self::ENROLLMENTS_CREATE,
                self::ENROLLMENTS_UPDATE,
            ],
            \App\Models\Role::STUDENT => [],
        ];
    }
}
