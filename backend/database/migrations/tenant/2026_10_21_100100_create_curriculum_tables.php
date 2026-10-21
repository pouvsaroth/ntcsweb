<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School setup, stage 2 (docs/academics/school-setup.md): subjects by grade
 * level and the curriculum.
 *
 *  - book_grade_level: the subjects (a school's Books) taught at a grade
 *    level, with the study hours per week.
 *  - curriculum_lessons: the lessons of one subject at one grade level, in
 *    order, each with its hours.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_grade_level', function (Blueprint $table) {
            $table->id();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->foreignId('grade_level_id')->constrained('grade_levels')->cascadeOnDelete();
            $table->decimal('hours_per_week', 5, 2)->nullable();
            $table->timestamps();

            $table->unique(['grade_level_id', 'book_id']);
            $table->index('book_id');
        });

        Schema::create('curriculum_lessons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grade_level_id')->constrained('grade_levels')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();
            $table->string('title');
            $table->decimal('hours', 6, 2)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['grade_level_id', 'book_id']);
        });

        $db = DB::connection();
        foreach (['book_grade_level', 'curriculum_lessons'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('curriculum_lessons');
        Schema::dropIfExists('book_grade_level');
    }
};
