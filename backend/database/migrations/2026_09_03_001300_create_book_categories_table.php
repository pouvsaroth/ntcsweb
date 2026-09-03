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

            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->foreignId('academic_program_id')->constrained('academic_programs')->restrictOnDelete();
            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'academic_program_id', 'name']);
            $table->index(['tenant_id', 'academic_program_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('book_categories');
    }
};
