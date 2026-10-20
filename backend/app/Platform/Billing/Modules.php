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
                'recruitment', 'resignation-requests', 'performance',
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
                // Home slides, gallery, downloads, promotions and the About page are
                // edited in the back office now (docs/backoffice/stage-7-website.md).
                'videos', 'contact-messages',
            ],
        ],
        'projects' => [
            'label' => 'Projects',
            'label_km' => 'គម្រោង',
            'groups' => ['projects'],
        ],
    ];

    /**
     * Each module's menus — one per permission group — as the plan form lists them
     * (a plan may include a module but only some of its menus).
     *
     * @var array<string, array{0: string, 1: string}> group => [English, Khmer]
     */
    public const MENU_LABELS = [
        'academic-programs' => ['Academic programs', 'កម្មវិធីសិក្សា'],
        'academic-years' => ['Academic years', 'ឆ្នាំសិក្សា'],
        'academic-reports' => ['Academic reports', 'របាយការណ៍សិក្សា'],
        'programs' => ['Programs', 'កម្មវិធី'],
        'course-packages' => ['Course packages', 'កញ្ចប់វគ្គសិក្សា'],
        'classes' => ['Classes', 'ថ្នាក់'],
        'classrooms' => ['Classrooms', 'បន្ទប់រៀន'],
        'buildings' => ['Buildings', 'អគារ'],
        'students' => ['Students', 'សិស្ស'],
        'enrollments' => ['Enrollments', 'ការចុះឈ្មោះចូលរៀន'],
        'attendance' => ['Student attendance', 'វត្តមានសិស្ស'],
        'make-up-class-requests' => ['Make-up class requests', 'សំណើរៀនសង'],
        'exam-applications' => ['Exam applications', 'ពាក្យប្រឡង'],
        'exam-scores' => ['Exam scores', 'ពិន្ទុប្រឡង'],
        'examination' => ['Examination', 'ការប្រឡង'],
        'student-feedback' => ['Student feedback', 'មតិសិស្ស'],
        'invoices' => ['Invoices', 'វិក្កយបត្រ'],
        'payments' => ['Payments', 'ការបង់ប្រាក់'],
        'receipts' => ['Receipts', 'បង្កាន់ដៃ'],
        'billing-reports' => ['Billing reports', 'របាយការណ៍វិក្កយបត្រ'],
        'products' => ['Products', 'ផលិតផល'],
        'accounting' => ['Accounting', 'គណនេយ្យ'],
        'accounts' => ['Chart of accounts', 'តារាងគណនី'],
        'income' => ['Income', 'ចំណូល'],
        'expense' => ['Expenses', 'ចំណាយ'],
        'transactions' => ['Transactions', 'ប្រតិបត្តិការ'],
        'reports' => ['Financial reports', 'របាយការណ៍ហិរញ្ញវត្ថុ'],
        'staff' => ['Staff', 'បុគ្គលិក'],
        'staff-attendance' => ['Staff attendance', 'វត្តមានបុគ្គលិក'],
        'positions' => ['Positions', 'មុខតំណែង'],
        'leave-management' => ['Leave management', 'គ្រប់គ្រងការឈប់សម្រាក'],
        'leave-requests' => ['Leave requests', 'សំណើឈប់សម្រាក'],
        'recruitment' => ['Recruitment', 'ការជ្រើសរើសបុគ្គលិក'],
        'resignation-requests' => ['Resignation requests', 'សំណើលាលែង'],
        'performance' => ['Performance', 'ការវាយតម្លៃការងារ'],
        'overtime-requests' => ['Overtime requests', 'សំណើម៉ោងបន្ថែម'],
        'manpower-requests' => ['Manpower requests', 'សំណើបុគ្គលិកបន្ថែម'],
        'attendance-corrections' => ['Attendance corrections', 'កែតម្រូវវត្តមាន'],
        'payroll' => ['Payroll', 'បើកប្រាក់បៀវត្សរ៍'],
        'assets' => ['Assets', 'ទ្រព្យសម្បត្តិ'],
        'my-assets' => ['My assets', 'ទ្រព្យសម្បត្តិរបស់ខ្ញុំ'],
        'books' => ['Books', 'សៀវភៅ'],
        'book-categories' => ['Book categories', 'ប្រភេទសៀវភៅ'],
        'videos' => ['Video lessons', 'មេរៀនវីដេអូ'],
        'contact-messages' => ['Contact messages', 'សារទំនាក់ទំនង'],
        'projects' => ['Projects', 'គម្រោង'],
    ];

    /**
     * Single permissions in a Core group that still belong to a module — the
     * dashboard's school tiles and stats (docs/backoffice/stage-9-products.md,
     * 9d): a customer without Academics or Finance (an ERP/HR-only customer)
     * never sees student or tuition tiles. Module only; they are not menus.
     *
     * @var array<string, string> permission slug => module
     */
    public const SLUG_MODULES = [
        'dashboard.cards.register-student' => 'academics',
        'dashboard.cards.enrollment' => 'academics',
        'dashboard.cards.classes' => 'academics',
        'dashboard.cards.student-attendance' => 'academics',
        'dashboard.cards.registration-pending' => 'academics',
        'dashboard.attendance.view' => 'academics',
        'dashboard.cards.teacher-attendance' => 'hrm',
        'dashboard.cards.student-payment' => 'finance',
        'dashboard.cards.invoices' => 'finance',
        'dashboard.finance.view' => 'finance',
        'dashboard.payment-alerts.view' => 'finance',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return list<array{code: string, label: string, label_km: string, groups: list<string>, menus: list<array{code: string, label: string, label_km: string}>}> */
    public static function list(): array
    {
        $out = [];
        foreach (self::CATALOG as $code => $module) {
            $out[] = ['code' => $code] + $module + ['menus' => array_map(fn (string $g) => [
                'code' => $g,
                'label' => self::MENU_LABELS[$g][0] ?? $g,
                'label_km' => self::MENU_LABELS[$g][1] ?? $g,
            ], $module['groups'])];
        }

        return $out;
    }

    /** Every menu (permission group) that belongs to a module. @return list<string> */
    public static function groups(): array
    {
        return array_merge(...array_values(array_map(fn (array $m) => $m['groups'], self::CATALOG)));
    }

    /**
     * A plan's menus as stored: only menus of its modules, and null when every
     * one of its modules has all its menus (so a plan saved before menus
     * existed, and one that simply keeps everything, read the same).
     *
     * @param  list<string>  $modules
     * @param  list<string>|null  $menus
     * @return list<string>|null
     */
    public static function normaliseMenus(array $modules, ?array $menus): ?array
    {
        if ($menus === null) {
            return null;
        }
        $inModules = array_merge([], ...array_map(fn (string $m) => self::CATALOG[$m]['groups'] ?? [], $modules));
        $kept = array_values(array_intersect($inModules, $menus)); // catalog order

        return count($kept) === count($inModules) ? null : $kept;
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
