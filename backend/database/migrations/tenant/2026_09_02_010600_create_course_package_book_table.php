<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which books a package bundles, and in what order -- e.g. "MS Word 2024"
 * = MS Word, Excel, PowerPoint, Photoshop, each modelled as a Book. There is
 * deliberately no separate "Course" concept -- a Book already is "a subject
 * a student can take, with a fee" (see the existing class_book pivot), so a
 * Course Package bundles Books directly instead of duplicating that idea
 * under a second name. `restrictOnDelete` on book_id: a book already
 * bundled into a live-priced package shouldn't silently vanish out from
 * under it. No `tenant_id` here, deliberately -- both sides are already
 * tenant-scoped via their own FKs (mirrors class_book's exact shape), so a
 * plain `sync()` never needs to know the ambient tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('course_package_book', function (Blueprint $table) {
            $table->foreignId('course_package_id')->constrained('course_packages')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->restrictOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_required')->default(true);

            $table->primary(['course_package_id', 'book_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('course_package_book');
    }
};
