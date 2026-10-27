<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A table where several students may sit at once (e.g. a long bench or a
 * lab bench): the "this table is already taken" checks skip it.
 *
 * The database index that allowed one studying student per table cannot
 * look at this flag, so it goes; the app checks keep normal tables single.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->boolean('allow_many_students')->default(false);
        });

        DB::statement('DROP INDEX IF EXISTS enrollments_class_table_active_unique');
    }

    public function down(): void
    {
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS enrollments_class_table_active_unique ON enrollments (class_id, table_id) WHERE status = 'active' AND table_id IS NOT NULL");

        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->dropColumn('allow_many_students');
        });
    }
};
