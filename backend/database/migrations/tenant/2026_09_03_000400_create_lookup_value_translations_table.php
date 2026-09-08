<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display text is data, never an identifier. A lookup_values row is only
 * ever a stable `code`; every one of its human-readable names/descriptions
 * across every language lives here instead, so adding a new language later
 * (th, vi, fr...) is one more `languages` row plus these translation rows --
 * never a schema change to lookup_values itself. No tenant_id column here,
 * deliberately -- it's already tenant-scoped transitively via
 * lookup_value_id (mirrors class_book/program_book's own shape).
 *
 * No DB-level foreign key on `language_id`: `languages` is platform-global
 * and stays in the central database while this table lives in each school's
 * own per-tenant database (see database/migrations/tenant), and a
 * cross-database foreign key isn't possible in Postgres regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_value_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookup_value_id')->constrained('lookup_values')->cascadeOnDelete();
            $table->unsignedBigInteger('language_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->unique(['lookup_value_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_value_translations');
    }
};
