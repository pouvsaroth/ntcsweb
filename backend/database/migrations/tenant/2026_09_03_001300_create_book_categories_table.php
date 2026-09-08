<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. Each row belongs to exactly one Academic Program — e.g.
 * "Office"/"Design" under Computer, "Kindergarten 1" under English — which is
 * what lets the Book form's Category dropdown filter down to just the
 * categories that make sense once a program is picked. A dedicated table
 * rather than a generic Base Data lookup value, since a lookup value has no
 * way to carry this FK cleanly (see the sibling migration on `books`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('book_categories', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            // No DB-level foreign key: `academic_programs` hasn't moved to a
            // per-tenant database yet, and a cross-database foreign key
            // isn't possible in Postgres regardless.
            $table->unsignedBigInteger('academic_program_id');
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['academic_program_id', 'name']);
            $table->index('academic_program_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_categories');
    }
};
