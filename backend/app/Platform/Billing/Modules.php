<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/**
 * The console's modules, as plans sell them (docs/backoffice/stage-5-billing.md, 5c).
 *
 * A module is a named list of console permission groups — the part before the
 * dot in `students.create`. A plan lists the module codes it includes; a
 * group in no module here is Core and always on.
 *
 * Adding a console feature: put its permission group in a module in the same
 * commit, or it is Core (every plan gets it).
 */
final class Modules
{
    /** @var array<string, array{label: string, label_km: string, groups: list<string>}> */
    public const CATALOG = [
        'academics' => [
            'label' => 'Academics',
            'label_km' => 'សិក្សា',
            'groups' => [
                'academic-programs', 'academic-years', 'academic-reports', 'programs', 'course-packages',
                'classes', 'classrooms', 'buildings', 'students', 'enrollments', 'attendance',
                'make-up-class-requests', 'exam-applications', 'exam-scores', 'examination', 'student-feedback',
            ],
        ],
        'finance' => [
            'label' => 'Finance',
            'label_km' => 'ហិរញ្ញវត្ថុ',
            'groups' => ['invoices', 'payments', 'receipts', 'billing-reports', 'products'],
        ],
        'accounting' => [
            'label' => 'Accounting',
            'label_km' => 'គណនេយ្យ',
            'groups' => ['accounting', 'accounts', 'income', 'expense', 'transactions', 'reports'],
        ],
        'hrm' => [
            'label' => 'HRM',
            'label_km' => 'ធនធានមនុស្ស',
            'groups' => [
                'staff', 'staff-attendance', 'positions', 'leave-management', 'leave-requests',
                'recruitment', 'resignation-requests', 'performance', 'promotions',
                'overtime-requests', 'manpower-requests', 'attendance-corrections',
            ],
        ],
        'payroll' => [
            'label' => 'Payroll',
            'label_km' => 'បើកប្រាក់បៀវត្សរ៍',
            'groups' => ['payroll'],
        ],
        'assets' => [
            'label' => 'Assets',
            'label_km' => 'ទ្រព្យសម្បត្តិ',
            'groups' => ['assets', 'my-assets'],
        ],
        'library' => [
            'label' => 'Library',
            'label_km' => 'បណ្ណាល័យ',
            'groups' => ['books', 'book-categories'],
        ],
        'website' => [
            'label' => 'Website',
            'label_km' => 'គេហទំព័រ',
            'groups' => [
                'news', 'events', 'gallery', 'home-slides', 'videos', 'announcements', 'contact-messages',
                'downloads', 'documents',
            ],
        ],
        'projects' => [
            'label' => 'Projects',
            'label_km' => 'គម្រោង',
            'groups' => ['projects'],
        ],
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return list<array{code: string, label: string, label_km: string, groups: list<string>}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $module) {
            $out[] = ['code' => $code] + $module;
        }

        return $out;
    }

    /** The module a permission group belongs to, or null for Core. */
    public static function moduleOfGroup(string $group): ?string
    {
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::CATALOG as $code => $module) {
                foreach ($module['groups'] as $g) {
                    $index[$g] = $code;
                }
            }
        }

        return $index[$group] ?? null;
    }
}
