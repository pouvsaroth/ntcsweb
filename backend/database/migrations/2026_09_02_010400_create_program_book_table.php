<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Many-to-many: which books belong to which academic program(s) — a book
 * can be reused across programs. No `tenant_id` here, deliberately — both
 * `program_id`/`book_id` are already tenant-scoped via their own FKs
 * (mirrors the existing `class_book` pivot's exact shape), which also means
 * a plain `sync()`/`attach()` never needs to know the ambient tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('program_book', function (Blueprint $table) {
            $table->foreignId('program_id')->constrained('academic_programs')->cascadeOnDelete();
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();

            $table->primary(['program_id', 'book_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('program_book');
    }
};
