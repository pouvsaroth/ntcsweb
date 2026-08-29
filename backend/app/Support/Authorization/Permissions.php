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

    // Programs — the public marketing catalog of courses (not to be confused
    // with `classes`, an actual scheduled teaching group).
    public const PROGRAMS_VIEW = 'programs.view';

    public const PROGRAMS_CREATE = 'programs.create';

    public const PROGRAMS_UPDATE = 'programs.update';

    public const PROGRAMS_DELETE = 'programs.delete';

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
            'Programs' => [
                self::PROGRAMS_VIEW => 'View programs',
                self::PROGRAMS_CREATE => 'Create programs',
                self::PROGRAMS_UPDATE => 'Update programs',
                self::PROGRAMS_DELETE => 'Delete programs',
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
            self::PROGRAMS_VIEW, self::PROGRAMS_CREATE, self::PROGRAMS_UPDATE, self::PROGRAMS_DELETE,
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
                ...$academicManagement,
            ],
            // Read-only across the board: a teacher needs to see their
            // roster, room, and materials, but the catalog itself (who
            // teaches what, in which room) is a school-admin decision. Write
            // access to teaching-specific records (attendance, grades) is a
            // later phase, not blanket write access to these master records.
            \App\Models\Role::TEACHER => [
                self::USERS_VIEW,
                self::TEACHERS_VIEW,
                self::STUDENTS_VIEW,
                self::CLASSROOMS_VIEW,
                self::BOOKS_VIEW,
                self::CLASSES_VIEW,
                self::ENROLLMENTS_VIEW,
            ],
            // Staff commonly handle front-desk registration, so they can
            // create/update students and enrollments, but not delete them
            // (deletion stays a school-admin action) and can't touch the
            // teaching catalog (teachers/classrooms/books/classes) itself.
            \App\Models\Role::STAFF => [
                self::USERS_VIEW,
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
