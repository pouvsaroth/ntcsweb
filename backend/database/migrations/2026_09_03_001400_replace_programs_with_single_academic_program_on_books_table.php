<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * A book belongs to exactly one Academic Program (not many) — that single
 * program is what drives which Book Categories (see book_categories) show up
 * for it. Replaces the `program_book` many-to-many pivot with a plain FK, and
 * replaces last turn's free-text `category` column (a Base Data lookup code)
 * with a real FK into the new book_categories table.
 *
 * `academic_program_id` is nullable at the schema level even though every
 * new write requires it (see StoreBookRequest) — a handful of existing books
 * predate this column and have no program to backfill from; the app layer,
 * not the schema, is what enforces "required" going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('books', function (Blueprint $table) {
            // No DB-level foreign key: `academic_programs` lives in each
            // school's own per-tenant database (see
            // database/migrations/tenant), and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('academic_program_id')->nullable()->after('cover_image');
        });

        DB::statement('
            UPDATE books
            SET academic_program_id = (
                SELECT program_id FROM program_book
                WHERE program_book.book_id = books.id
                ORDER BY program_id
                LIMIT 1
            )
        ');

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('category');
            // No DB-level foreign key: `book_categories` lives in each
            // school's own per-tenant database (see
            // database/migrations/tenant), and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('book_category_id')->nullable()->after('academic_program_id');
        });

        Schema::dropIfExists('program_book');
    }

    public function down(): void
    {
        Schema::create('program_book', function (Blueprint $table) {
            $table->unsignedBigInteger('program_id');
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();

            $table->primary(['program_id', 'book_id']);
            $table->index('book_id');
        });

        DB::statement('
            INSERT INTO program_book (program_id, book_id)
            SELECT academic_program_id, id FROM books WHERE academic_program_id IS NOT NULL
        ');

        Schema::table('books', function (Blueprint $table) {
            $table->dropColumn('book_category_id');
            $table->dropColumn('academic_program_id');
            $table->string('category', 50)->nullable()->after('cover_image');
        });
    }
};
