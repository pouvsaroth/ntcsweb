<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the package-based enrollment path alongside the existing book-based
 * one — every existing row is left exactly as it is; nothing here changes
 * what an existing enrollment/invoice/payment means.
 *
 * `book_id` is relaxed from NOT NULL to nullable: every existing row already
 * has it set (no data is affected), but a package-path enrollment has no
 * book at all. `course_package_id`/`academic_program_id`/`study_mode_id`
 * are new nullable columns, populated only by the new
 * EnrollmentService::enrollInPackage() path; the legacy
 * EnrollmentController::store() book path is completely untouched and
 * leaves them null.
 *
 * The old `(student_id, class_id, book_id)` unique constraint is replaced
 * by two PARTIAL unique indexes scoped to non-dropped rows. A plain full
 * unique would wrongly block "drop this enrollment, then re-enroll the same
 * student in the same class/package later" (the spec's own re-enrollment
 * requirement) — the dropped row would permanently occupy the slot. Every
 * existing row already satisfies the partial predicate (status <> 'dropped'
 * covers 'active'/'completed' identically to how the old full unique
 * already covered them), so this is a schema redefinition, not a data
 * change. Laravel's fluent schema builder has no partial-index API, hence
 * the raw DB::statement calls.
 *
 * The CHECK constraint is defense-in-depth, not the only enforcement —
 * EnrollmentService validates book-XOR-package at the application layer
 * too — but costs nothing and every existing row already satisfies it
 * (book_id set, course_package_id null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique('enrollments_student_id_class_id_book_id_unique');

            $table->unsignedBigInteger('book_id')->nullable()->change();

            $table->foreignId('course_package_id')->nullable()->after('book_id')
                ->constrained('course_packages')->restrictOnDelete();

            $table->foreignId('academic_program_id')->nullable()->after('course_package_id')
                ->constrained('academic_programs')->nullOnDelete();

            $table->foreignId('study_mode_id')->nullable()->after('academic_program_id')
                ->constrained('study_modes')->nullOnDelete();

            $table->index(['tenant_id', 'course_package_id']);
        });

        DB::statement("CREATE UNIQUE INDEX enrollments_student_class_book_active_unique ON enrollments (student_id, class_id, book_id) WHERE status <> 'dropped'");
        DB::statement("CREATE UNIQUE INDEX enrollments_student_class_package_active_unique ON enrollments (student_id, class_id, course_package_id) WHERE status <> 'dropped'");
        DB::statement('ALTER TABLE enrollments ADD CONSTRAINT enrollments_book_xor_package CHECK ((book_id IS NOT NULL)::int + (course_package_id IS NOT NULL)::int = 1)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE enrollments DROP CONSTRAINT IF EXISTS enrollments_book_xor_package');
        DB::statement('DROP INDEX IF EXISTS enrollments_student_class_package_active_unique');
        DB::statement('DROP INDEX IF EXISTS enrollments_student_class_book_active_unique');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('study_mode_id');
            $table->dropConstrainedForeignId('academic_program_id');
            $table->dropConstrainedForeignId('course_package_id');

            $table->unsignedBigInteger('book_id')->nullable(false)->change();

            $table->unique(['student_id', 'class_id', 'book_id']);
        });
    }
};
