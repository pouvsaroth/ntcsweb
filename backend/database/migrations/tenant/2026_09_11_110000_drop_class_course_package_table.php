<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class-to-package "menu" never became load-bearing: EnrollmentService's
 * assertEnrollable() only checks that a package's academic_program_id
 * matches the class's — it never consults this pivot — and no tenant ever
 * populated it. `class_book` (the class's own book list) is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('class_course_package');
    }

    public function down(): void
    {
        Schema::create('class_course_package', function (Blueprint $table) {
            $table->unsignedBigInteger('class_id');
            $table->foreignId('course_package_id')->constrained('course_packages')->cascadeOnDelete();

            $table->primary(['class_id', 'course_package_id']);
            $table->index('course_package_id');
        });
    }
};
