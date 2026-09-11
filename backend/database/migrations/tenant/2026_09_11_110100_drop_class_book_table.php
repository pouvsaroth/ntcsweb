<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The class-level book list was never surfaced in the admin Class form (no
 * picker was ever built for it) and no tenant ever populated it —
 * `CoursePackage::books()` (course_package_book) is the book list that
 * actually matters. See the sibling migration dropping class_course_package
 * for the same cleanup on that pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('class_book');
    }

    public function down(): void
    {
        Schema::create('class_book', function (Blueprint $table) {
            $table->unsignedBigInteger('class_id');
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();

            $table->primary(['class_id', 'book_id']);
            $table->index('book_id');
        });
    }
};
