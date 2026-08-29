<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Superseded by the `student_guardians` table: the old `t_student` legacy
 * system this platform mirrors actually keeps guardians in their own table
 * (`t_school_student_guardian`, one row per guardian — a student can have
 * more than one), not as flat columns on the student itself. The
 * `guardian_name`/`guardian_phone` pair added when `students` was first
 * created assumed a single guardian and was wrong; nothing has used them
 * (0 rows exist in this table as of this migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['guardian_name', 'guardian_phone']);
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('guardian_name')->nullable()->after('photo_path');
            $table->string('guardian_phone', 32)->nullable()->after('guardian_name');
        });
    }
};
