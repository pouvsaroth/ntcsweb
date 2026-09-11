<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A class can now have several teaching staff instead of exactly one —
 * tagged 'teacher' or 'assistant' on the new pivot, both roles repeatable.
 * Existing `classes.teacher_id` values are carried over as 'teacher' rows
 * before the column is dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_teachers', function (Blueprint $table) {
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('role', 20); // teacher | assistant
            $table->timestamps();

            $table->primary(['class_id', 'staff_id']);
            $table->index('staff_id');
        });

        DB::statement(<<<'SQL'
            INSERT INTO class_teachers (class_id, staff_id, role, created_at, updated_at)
            SELECT id, teacher_id, 'teacher', now(), now()
            FROM classes
            WHERE teacher_id IS NOT NULL
        SQL);

        Schema::table('classes', function (Blueprint $table) {
            $table->dropColumn('teacher_id');
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->index('teacher_id');
        });

        DB::statement(<<<'SQL'
            UPDATE classes c
            SET teacher_id = ct.staff_id
            FROM class_teachers ct
            WHERE ct.class_id = c.id AND ct.role = 'teacher'
        SQL);

        Schema::dropIfExists('class_teachers');
    }
};
