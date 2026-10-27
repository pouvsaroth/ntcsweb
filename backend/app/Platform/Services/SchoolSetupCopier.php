<?php

declare(strict_types=1);

namespace App\Platform\Services;

use App\Models\Role;
use App\Models\Tenant;
use App\Support\Tenancy\SchoolConnection;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Copies one school's SETUP into a newly created school (back office →
 * Create school → "Copy setup from"): programs, courses and prices, classes
 * and rooms, timetable, lookups, chart of accounts, HRM / approval / asset
 * setup, custom roles and a few school settings.
 *
 * Never copied: logins, students, staff and anything they did (enrollments,
 * invoices, payments, attendance…), number sequences, and the other school's
 * identity (logo, stamp, bank KHQR, website content).
 *
 * Most schools share one database, so every copied row gets a NEW id and
 * every link is re-pointed through an old→new id map. A link to something
 * that is not copied (a teacher, a user) is emptied. The new school's own
 * seeded rows in a copied table are replaced by the source's, so the school
 * ends up with one set, not two. Runs in one transaction: all or nothing.
 */
final class SchoolSetupCopier
{
    /** Copied tables, parents before the tables that point at them. */
    public const TABLES = [
        'academic_programs', 'programs', 'academic_years', 'academic_terms', 'grade_levels',
        'book_categories', 'books', 'book_grade_level', 'curriculum_lessons',
        'assessment_schemes', 'assessment_components', 'grade_bands', 'competency_levels', 'competency_criteria', 'promotion_rules',
        'accounts', 'products', 'product_variants', 'course_packages', 'course_package_book',
        'branches', 'buildings', 'classrooms', 'classroom_tables', 'classes', 'class_schedules',
        'holidays', 'school_calendar_events',
        'lookup_categories', 'lookup_values', 'lookup_value_translations', 'currency_rates',
        'departments', 'positions', 'teams', 'job_grades', 'job_levels', 'leave_types', 'leave_policies',
        'shifts', 'shift_days', 'work_schedules',
        'payroll_components', 'payroll_settings', 'performance_settings', 'tax_brackets', 'social_security_schemes',
        'salary_structures', 'salary_structure_items', 'evaluation_forms', 'evaluation_form_questions', 'kpis',
        'form_categories', 'form_templates', 'approval_groups', 'approval_flow_steps',
        'asset_categories', 'asset_locations', 'suppliers', 'repair_shops',
    ];

    /** Links the database does not declare as foreign keys: column => table it points at. */
    /**
     * What staff can pick to copy (all by default). `needs`: parts that must
     * come with it, because its rows point at theirs (classes need rooms…).
     * Every table in TABLES is in exactly one part.
     */
    public const PARTS = [
        'programs' => ['tables' => ['academic_programs', 'programs', 'academic_years', 'academic_terms', 'grade_levels', 'holidays', 'school_calendar_events'], 'needs' => []],
        'books' => ['tables' => ['book_categories', 'books', 'book_grade_level', 'curriculum_lessons'], 'needs' => ['programs']],
        'grading' => ['tables' => ['assessment_schemes', 'assessment_components', 'grade_bands', 'competency_levels', 'competency_criteria', 'promotion_rules'], 'needs' => ['programs']],
        'accounting' => ['tables' => ['accounts', 'currency_rates'], 'needs' => []],
        'courses' => ['tables' => ['products', 'product_variants', 'course_packages', 'course_package_book'], 'needs' => ['programs', 'books', 'accounting']],
        'rooms' => ['tables' => ['branches', 'buildings', 'classrooms', 'classroom_tables'], 'needs' => []],
        'classes' => ['tables' => ['classes', 'class_schedules'], 'needs' => ['programs', 'rooms', 'books']],
        'lists' => ['tables' => ['lookup_categories', 'lookup_values', 'lookup_value_translations'], 'needs' => []],
        'roles' => ['tables' => [], 'needs' => []],
        'hrm' => ['tables' => [
            'departments', 'positions', 'teams', 'job_grades', 'job_levels', 'leave_types', 'leave_policies', 'shifts', 'shift_days', 'work_schedules',
            'payroll_components', 'payroll_settings', 'performance_settings', 'tax_brackets', 'social_security_schemes',
            'salary_structures', 'salary_structure_items', 'evaluation_forms', 'evaluation_form_questions', 'kpis',
        ], 'needs' => ['roles']],
        'approvals' => ['tables' => ['form_categories', 'form_templates', 'approval_groups', 'approval_flow_steps'], 'needs' => []],
        'assets' => ['tables' => ['asset_categories', 'asset_locations', 'suppliers', 'repair_shops'], 'needs' => []],
        'settings' => ['tables' => [], 'needs' => []],
    ];

    /**
     * The parts picked plus everything they need. Null = every part.
     *
     * @param  list<string>|null  $parts
     * @return list<string>
     */
    public static function withNeeds(?array $parts): array
    {
        if ($parts === null) {
            return array_keys(self::PARTS);
        }
        $out = [];
        $add = function (string $part) use (&$add, &$out): void {
            if (! isset(self::PARTS[$part]) || in_array($part, $out, true)) {
                return;
            }
            $out[] = $part;
            foreach (self::PARTS[$part]['needs'] as $need) {
                $add($need);
            }
        };
        foreach ($parts as $part) {
            $add($part);
        }

        // In PARTS order, so the list reads the same however it was picked.
        return array_values(array_intersect(array_keys(self::PARTS), $out));
    }

    private const REFS = [
        'book_categories.academic_program_id' => 'academic_programs',
        'books.academic_program_id' => 'academic_programs',
        'books.book_category_id' => 'book_categories',
        'classes.classroom_id' => 'classrooms',
        'classes.academic_program_id' => 'academic_programs',
        'class_schedules.class_id' => 'classes',
        'classroom_tables.classroom_id' => 'classrooms',
        'classrooms.building_id' => 'buildings',
        'course_package_book.course_package_id' => 'course_packages',
        'course_packages.academic_program_id' => 'academic_programs',
        'product_variants.product_id' => 'products',
        'products.revenue_account_id' => 'accounts',
        'asset_locations.classroom_id' => 'classrooms',
    ];

    /** Columns pointing outside the school's own tables that stay as they are (shared lists). */
    private const KEEP = ['lookup_value_translations.language_id'];

    /** Uploaded files: copied into the new school's folder. */
    private const FILES = ['books.cover_image', 'course_packages.thumbnail_path', 'programs.image_path'];

    /** School settings (tenants.settings) carried over; the KHQR and website content are not. */
    private const SETTINGS = ['monthly_payment_alert_days', 'student_inactive_after_days', 'term_study_alert_days'];

    public function __construct(
        private readonly SchoolDatabase $database,
        private readonly TenantContext $context,
    ) {}

    /**
     * @param  list<string>|null  $parts  What to copy (PARTS keys); null = everything.
     * @return array<string, int> rows copied per table
     */
    public function copy(Tenant $source, Tenant $target, ?array $parts = null): array
    {
        $parts = self::withNeeds($parts);
        $tables = array_values(array_filter(self::TABLES, fn (string $table) => in_array($table, array_merge(...array_map(fn ($p) => self::PARTS[$p]['tables'], $parts)), true)));

        if ((int) $source->getKey() === (int) $target->getKey()) {
            throw new RuntimeException('A school cannot copy its own setup.');
        }

        // 1. Read the source school's setup rows.
        $rows = $this->database->within($source, fn () => $this->context->runFor($source, function () use ($source, $tables) {
            $db = DB::connection(SchoolConnection::NAME);
            $out = [];
            foreach ($tables as $table) {
                if (! $db->getSchemaBuilder()->hasTable($table)) {
                    continue;
                }
                $query = $this->own($db, $table, $source);
                if ($db->getSchemaBuilder()->hasColumn($table, 'id')) {
                    $query->orderBy('id');
                }
                $out[$table] = array_map(fn ($r) => (array) $r, $query->get()->all());
            }

            return $out;
        }));

        // 2. Roles first (central): the new school's positions point at them.
        $roleMap = in_array('roles', $parts, true) ? $this->copyRoles($source, $target) : [];

        // 3. Write into the new school.
        $counts = $this->database->within($target, fn () => $this->context->runFor($target, function () use ($rows, $roleMap, $source, $target) {
            $db = DB::connection(SchoolConnection::NAME);

            return $db->transaction(fn () => $this->write($db, $rows, $roleMap, $source, $target));
        }));

        if (in_array('settings', $parts, true) || in_array('accounting', $parts, true)) {
            $this->copySettings($source, $target, $counts['__accounts'] ?? [], in_array('settings', $parts, true));
        }
        unset($counts['__accounts']);

        return $counts;
    }

    /**
     * @param  array<string, list<array<string, mixed>>>  $rows
     * @param  array<int, int>  $roleMap
     * @return array<string, mixed>
     */
    private function write(Connection $db, array $rows, array $roleMap, Tenant $source, Tenant $target): array
    {
        $fks = $this->foreignKeys($db);
        $refs = fn (string $table, string $column): ?string => $fks["{$table}.{$column}"] ?? self::REFS["{$table}.{$column}"] ?? null;

        // Replace the new school's seeded rows only where the source has some;
        // a table pointing at a replaced table is cleared with it.
        $replace = array_keys(array_filter($rows, fn ($r) => $r !== []));
        do {
            $before = count($replace);
            foreach (array_keys($rows) as $table) {
                if (in_array($table, $replace, true)) {
                    continue;
                }
                foreach ($this->columns($db, $table) as $column) {
                    $parent = $refs($table, $column);
                    if ($parent !== null && $parent !== $table && in_array($parent, $replace, true)) {
                        $replace[] = $table;
                        break;
                    }
                }
            }
        } while (count($replace) !== $before);

        foreach (array_reverse(self::TABLES) as $table) {
            if (in_array($table, $replace, true)) {
                $this->own($db, $table, $target)->delete();
            }
        }

        $map = [];
        $counts = [];
        foreach (self::TABLES as $table) {
            if (! in_array($table, $replace, true) || ($rows[$table] ?? []) === []) {
                continue;
            }
            $columns = $this->columns($db, $table);
            $nullable = $this->nullableColumns($db, $table);
            $hasId = in_array('id', $columns, true);
            $selfRefs = [];

            $copied = 0;
            foreach ($rows[$table] as $row) {
                $oldId = $row['id'] ?? null;
                $new = [];
                try {
                    foreach ($row as $column => $value) {
                        if ($column === 'id' || $column === 'tenant_id' || ! in_array($column, $columns, true)) {
                            continue;
                        }
                        $new[$column] = $this->value($table, $column, $value, $refs, $map, $roleMap, $nullable, $selfRefs, $oldId, $source, $target);
                    }
                } catch (SetupRowLeftover) {
                    continue;
                }
                $copied++;

                if ($hasId) {
                    $newId = (int) $db->table($table)->insertGetId($new);
                    $map[$table][(int) $oldId] = $newId;
                } else {
                    $db->table($table)->insert($new);
                }
            }

            // A row pointing at a later row of its own table (a parent account
            // listed after its child): set once the whole table is in.
            foreach ($selfRefs as [$column, $oldId, $oldParent]) {
                if (isset($map[$table][$oldParent], $map[$table][$oldId])) {
                    $db->table($table)->where('id', $map[$table][$oldId])->update([$column => $map[$table][$oldParent]]);
                }
            }

            $counts[$table] = $copied;
        }

        $counts['__accounts'] = $map['accounts'] ?? [];

        return $counts;
    }

    /**
     * @param  callable(string, string): ?string  $refs
     * @param  array<string, array<int, int>>  $map
     * @param  list<array{0: string, 1: int, 2: int}>  $selfRefs
     */
    private function value(string $table, string $column, mixed $value, callable $refs, array $map, array $roleMap, array $nullable, array &$selfRefs, mixed $oldId, Tenant $source, Tenant $target): mixed
    {
        $key = "{$table}.{$column}";
        $canBeNull = in_array($column, $nullable, true);

        if (in_array($key, self::FILES, true)) {
            return $this->copyFile($value, $source, $target);
        }
        if ($value === null || in_array($key, self::KEEP, true)) {
            return $value;
        }
        if ($key === 'positions.role_id') {
            return $roleMap[(int) $value] ?? throw new RuntimeException("Position role {$value} has no match in the new school.");
        }
        // Who created / changed it: a login of the other school.
        if (str_ends_with($column, '_by') || $column === 'user_id') {
            return $canBeNull ? null : throw new RuntimeException("{$key} must point at a user, which is never copied.");
        }

        $parent = $refs($table, $column);
        if ($parent === null) {
            if (str_ends_with($column, '_id')) {
                // An id column whose table is unknown: never carry another school's id over.
                return $canBeNull ? null : throw new RuntimeException("Do not know where {$key} points; add it to SchoolSetupCopier.");
            }

            return $value;
        }

        if (isset($map[$parent][(int) $value])) {
            return $map[$parent][(int) $value];
        }
        if ($parent === $table) {
            // Parent row comes later in the list: set once the whole table is in.
            $selfRefs[] = [$column, (int) $oldId, (int) $value];

            return null;
        }
        if (in_array($parent, self::TABLES, true)) {
            // A setup row that is gone (e.g. a course deleted long ago, its
            // book links left behind): emptied, or the leftover row skipped.
            return $canBeNull ? null : throw new SetupRowLeftover;
        }

        // Points at something not copied (a teacher, a student…): emptied.
        return $canBeNull ? null : throw new RuntimeException("{$key} points at {$parent}, which is not copied.");
    }

    private function copyFile(mixed $path, Tenant $source, Tenant $target): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }
        $disk = Storage::disk('public');
        if (! $disk->exists($path)) {
            return null;
        }
        $prefix = 'tenants/'.$source->getKey().'/';
        $newPath = Str::startsWith($path, $prefix)
            ? $target->storagePath(Str::after($path, $prefix))
            : $target->storagePath('copied', basename($path));
        if (! $disk->exists($newPath)) {
            $disk->copy($path, $newPath);
        }

        return $newPath;
    }

    /** Custom roles and their permissions; system roles already exist in the new school. @return array<int, int> */
    private function copyRoles(Tenant $source, Tenant $target): array
    {
        $central = DB::connection((new Role)->getConnectionName());
        $sourceRoles = $central->table('roles')->where('tenant_id', $source->getKey())->get();
        $targetRoles = $central->table('roles')->where('tenant_id', $target->getKey())->pluck('id', 'slug');
        // Roles shared by every school (no tenant) stay as they are.
        $map = $central->table('roles')->whereNull('tenant_id')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => (int) $id])->all();

        foreach ($sourceRoles as $role) {
            if (isset($targetRoles[$role->slug])) {
                $map[(int) $role->id] = (int) $targetRoles[$role->slug];

                continue;
            }
            $newId = (int) $central->table('roles')->insertGetId([
                'tenant_id' => $target->getKey(), 'slug' => $role->slug, 'name' => $role->name,
                'description' => $role->description, 'level' => $role->level, 'is_system' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissions = $central->table('permission_role')->where('role_id', $role->id)->pluck('permission_id');
            $central->table('permission_role')->insert($permissions->map(fn ($p) => ['role_id' => $newId, 'permission_id' => $p])->all());
            $map[(int) $role->id] = $newId;
        }

        return $map;
    }

    /** @param array<int, int> $accountMap */
    private function copySettings(Tenant $source, Tenant $target, array $accountMap, bool $alerts): void
    {
        $source = $source->fresh();
        $target = $target->fresh();
        $settings = $target->settings ?? [];

        foreach ($alerts ? self::SETTINGS : [] as $key) {
            if (isset($source->settings[$key])) {
                $settings[$key] = $source->settings[$key];
            }
        }

        // Default accounts for posting payments/expenses: re-pointed at the copied accounts.
        if (isset($source->settings['accounting']) && is_array($source->settings['accounting']) && $accountMap !== []) {
            $remap = function (mixed $value) use (&$remap, $accountMap): mixed {
                if (is_array($value)) {
                    return array_map($remap, $value);
                }

                return is_int($value) || (is_string($value) && ctype_digit($value)) ? ($accountMap[(int) $value] ?? null) : $value;
            };
            $settings['accounting'] = $remap($source->settings['accounting']);
        }

        $target->forceFill(['settings' => $settings, 'exam_fee_amount' => $alerts ? ($target->exam_fee_amount ?? $source->exam_fee_amount) : $target->exam_fee_amount])->save();
    }

    /** @return array<string, string> "table.column" => table it points at */
    private function foreignKeys(Connection $db): array
    {
        $out = [];
        foreach ($db->select(<<<'SQL'
            select child.relname as child, a.attname as col, parent.relname as parent
            from pg_constraint c
            join pg_class child on child.oid = c.conrelid
            join pg_class parent on parent.oid = c.confrelid
            join pg_attribute a on a.attrelid = c.conrelid and a.attnum = any(c.conkey)
            where c.contype = 'f' and child.relnamespace = 'public'::regnamespace
            SQL) as $fk) {
            $out["{$fk->child}.{$fk->col}"] = $fk->parent;
        }

        return $out;
    }

    /** The school's own rows (row security does this too; this is belt and braces). */
    private function own(Connection $db, string $table, Tenant $tenant): \Illuminate\Database\Query\Builder
    {
        $query = $db->table($table);

        return in_array('tenant_id', $this->columns($db, $table), true) ? $query->where('tenant_id', $tenant->getKey()) : $query;
    }

    /** @return list<string> */
    private function columns(Connection $db, string $table): array
    {
        return $db->getSchemaBuilder()->getColumnListing($table);
    }

    /** @return list<string> */
    private function nullableColumns(Connection $db, string $table): array
    {
        return array_column(array_filter($db->getSchemaBuilder()->getColumns($table), fn ($c) => $c['nullable']), 'name');
    }
}

/** A setup row whose required link points at a row that no longer exists: not copied. */
final class SetupRowLeftover extends RuntimeException {}
