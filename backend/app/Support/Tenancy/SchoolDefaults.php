<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * The default rows every school starts with — the "General" form category and
 * its three built-in templates, the payroll settings with Cambodia's tax
 * brackets and NSSF schemes, and the performance settings.
 *
 * These used to be inserted by school migrations (2026_09_07, 09_16 ×2,
 * 10_08, 10_09). That only works while every school has a database of its
 * own: a shared database (school_share) or the template is migrated once for
 * no school in particular, so a migration cannot know whose defaults it is
 * writing. They are created here instead, per school, with the school set on
 * the connection (so tenant_id is filled in): when a school is set up, on
 * every deploy (BaseDataSeeder), and for every test school (TenantFactory).
 *
 * Idempotent: anything already there is left alone. Same values as the
 * original migrations.
 */
final class SchoolDefaults
{
    /** Run with the school set on the `tenant` connection (TenantContext::runFor). */
    public static function seed(): void
    {
        $db = DB::connection(SchoolConnection::NAME);
        $now = now();

        $categoryId = $db->table('form_categories')->whereNull('deleted_at')->orderBy('id')->value('id')
            ?? $db->table('form_categories')->insertGetId([
                'name' => 'General', 'order' => 0, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);

        foreach ([
            ['code' => 'CHANGE-CLASS', 'name' => 'Request for Change Class', 'order' => 100],
            ['code' => 'EXTRA-CLASS', 'name' => 'Request for Extra Classes', 'order' => 101],
            ['code' => 'RESIGNATION', 'name' => 'Resignation Form', 'order' => 102],
        ] as $template) {
            if (! $db->table('form_templates')->where('code', $template['code'])->exists()) {
                $db->table('form_templates')->insert($template + [
                    'form_category_id' => $categoryId, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        self::once($db, 'payroll_settings', [['created_at' => $now, 'updated_at' => $now]]);
        self::once($db, 'performance_settings', [['created_at' => $now, 'updated_at' => $now]]);

        // Cambodia's monthly Tax on Salary (residents), in riel.
        self::once($db, 'tax_brackets', array_map(fn ($b) => $b + ['created_at' => $now, 'updated_at' => $now], [
            ['min_amount' => 0, 'max_amount' => 1500000, 'rate' => 0],
            ['min_amount' => 1500000, 'max_amount' => 2000000, 'rate' => 5],
            ['min_amount' => 2000000, 'max_amount' => 8500000, 'rate' => 10],
            ['min_amount' => 8500000, 'max_amount' => 12500000, 'rate' => 15],
            ['min_amount' => 12500000, 'max_amount' => null, 'rate' => 20],
        ]));

        // NSSF — wage counted between 400,000 and 1,200,000 riel a month.
        foreach ([
            ['code' => 'OR', 'name' => 'Occupational risk', 'employee_rate' => 0, 'employer_rate' => 0.8, 'reduces_taxable' => false],
            ['code' => 'HC', 'name' => 'Health care', 'employee_rate' => 0, 'employer_rate' => 2.6, 'reduces_taxable' => false],
            ['code' => 'PEN', 'name' => 'Pension', 'employee_rate' => 2, 'employer_rate' => 2, 'reduces_taxable' => true],
        ] as $scheme) {
            if (! $db->table('social_security_schemes')->where('code', $scheme['code'])->exists()) {
                $db->table('social_security_schemes')->insert($scheme + [
                    'min_wage' => 400000, 'max_wage' => 1200000, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    /** @param list<array<string, mixed>> $rows */
    private static function once(Connection $db, string $table, array $rows): void
    {
        if (! $db->table($table)->exists()) {
            $db->table($table)->insert($rows);
        }
    }
}
