<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class <-> Book, many-to-many: a class may use several books, and a book
 * may be reused across several classes. No tenant_id of its own — both sides
 * already belong to one tenant, the same pattern as permission_role/role_user.
 *
 * Lives in the tenant database, not the central one, even though `classes`
 * itself is still central: a BelongsToMany's query always runs on the
 * *related* model's connection (Book, here — see SchoolClass::books()), so
 * this pivot has to sit alongside `books` for that join to be a single,
 * same-database query. No DB-level foreign key on `class_id` — a
 * cross-database foreign key isn't possible in Postgres regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_book', function (Blueprint $table) {
            $table->unsignedBigInteger('class_id');
            $table->foreignId('book_id')->constrained('books')->cascadeOnDelete();

            $table->primary(['class_id', 'book_id']);
            $table->index('book_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_book');
    }
};
