<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which physical table within the class's classroom a student sits at — two
 * active students in the same class can never share a table (enforced by
 * the partial unique index below, scoped to non-dropped rows the same way
 * the existing student/class/book and student/class/package partial unique
 * indexes are, so dropping an enrollment frees its table for reuse). See
 * StoreEnrollmentRequest for where this becomes required (only once the
 * class's classroom actually has tables configured).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            // No DB-level foreign key: `classroom_tables` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('table_id')->nullable()->after('class_id');
        });

        DB::statement("CREATE UNIQUE INDEX enrollments_class_table_active_unique ON enrollments (class_id, table_id) WHERE status <> 'dropped' AND table_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS enrollments_class_table_active_unique');

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn('table_id');
        });
    }
};
