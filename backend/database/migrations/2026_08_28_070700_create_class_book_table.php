<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class <-> Book, many-to-many: a class may use several books, and a book
 * may be reused across several classes. No tenant_id of its own — both sides
 * already belong to one tenant, the same pattern as permission_role/role_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_book', function (Blueprint $table) {
            $table->foreignId('class_id')->constrained('classes')->cascadeOnDelete();
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
