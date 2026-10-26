<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A student who completed or abandoned a course may be enrolled into the
 * same class and course again (a retake), not only after dropping it.
 * The partial unique index now skips every finished status
 * (Enrollment::STATUSES_FINISHED), matching the enrollment form's rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rebuild("status NOT IN ('completed', 'abandoned', 'dropped')");
    }

    public function down(): void
    {
        $this->rebuild("status <> 'dropped'");
    }

    private function rebuild(string $where): void
    {
        // (The old book_id index went with that column in 2026_09_09_010000.)
        DB::statement('DROP INDEX IF EXISTS enrollments_student_class_package_active_unique');
        // Per school, like every unique index on a shared database (SchoolTables::secure()).
        DB::statement("CREATE UNIQUE INDEX enrollments_student_class_package_active_unique ON enrollments (tenant_id, student_id, class_id, course_package_id) WHERE {$where}");
    }
};
