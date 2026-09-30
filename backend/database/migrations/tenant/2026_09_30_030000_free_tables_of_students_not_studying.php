<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Narrows the "one student per table per class" index from every
 * non-dropped enrollment to Studying (active) ones only — see
 * Enrollment::TABLE_HOLDING_STATUS. Before this, a stopped/suspended/
 * completed/abandoned student kept their table forever, so it never came
 * back to the enrollment form's table picker.
 *
 * Always safe to apply: the new index covers a subset of the rows the old
 * one did, so existing data can't violate it. `down()` restores the old,
 * stricter index — that can fail if two non-dropped enrollments now share a
 * table (e.g. a stopped student's table was given to someone new), which is
 * exactly what this change allows.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS enrollments_class_table_active_unique');
        DB::statement("CREATE UNIQUE INDEX enrollments_class_table_active_unique ON enrollments (class_id, table_id) WHERE status = 'active' AND table_id IS NOT NULL");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS enrollments_class_table_active_unique');
        DB::statement("CREATE UNIQUE INDEX enrollments_class_table_active_unique ON enrollments (class_id, table_id) WHERE status <> 'dropped' AND table_id IS NOT NULL");
    }
};
