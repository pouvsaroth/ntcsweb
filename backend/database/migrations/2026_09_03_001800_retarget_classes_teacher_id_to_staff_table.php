<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A class's teacher is now a Staff member holding the "Teacher" position
 * (see TeacherPositionSeeder), not a row in the standalone `teachers` table
 * — which the very next migration drops entirely. Existing `teacher_id`
 * values are nulled out here: a Teacher row's id never corresponds to any
 * Staff row's id, so there is no automatic mapping to carry forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
        });

        DB::table('classes')->update(['teacher_id' => null]);

        Schema::table('classes', function (Blueprint $table) {
            $table->foreign('teacher_id')->references('id')->on('staff')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropForeign(['teacher_id']);
        });

        DB::table('classes')->update(['teacher_id' => null]);

        Schema::table('classes', function (Blueprint $table) {
            $table->foreign('teacher_id')->references('id')->on('teachers')->nullOnDelete();
        });
    }
};
