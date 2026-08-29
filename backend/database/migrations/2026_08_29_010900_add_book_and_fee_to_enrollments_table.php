<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "one class, one shared book" assumption doesn't hold in practice: two
 * students can share the exact same session (teacher, room, Sat-Sun
 * 1-3PM) while studying entirely different books at different prices — a
 * computer lab running several self-paced courses side by side, not one
 * class with one fixed curriculum. `class_book` (see that migration) becomes
 * the session's *menu* of books on offer; this migration records which one
 * item each individual student actually picked, and what they're being
 * charged for it.
 *
 * `fee` is a snapshot at enrollment time, not a live read of `books.fee` — a
 * later catalog price change must never retroactively alter what an
 * already-enrolled student owes. `EnrollmentController` defaults it from the
 * book's current fee when creating an enrollment, but it's stored on this
 * row and can be edited afterward (a discount, a scholarship) independently
 * of the book's own price.
 *
 * `restrictOnDelete()`, not `cascadeOnDelete()`, on `book_id`: an enrollment
 * with a fee attached is a financial/academic record, not disposable
 * metadata — force-deleting a book that has enrollments against it must be a
 * deliberate, blocked-until-handled decision, not something that silently
 * erases which book a payment was actually for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'class_id']);

            $table->foreignId('book_id')->after('class_id')->constrained('books')->restrictOnDelete();
            $table->decimal('fee', 10, 2)->after('enrolled_at');

            // A student can take more than one book within the same class
            // session now — one enrollment row per (student, class, book).
            $table->unique(['student_id', 'class_id', 'book_id']);
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'class_id', 'book_id']);
            $table->dropConstrainedForeignId('book_id');
            $table->dropColumn('fee');

            $table->unique(['student_id', 'class_id']);
        });
    }
};
