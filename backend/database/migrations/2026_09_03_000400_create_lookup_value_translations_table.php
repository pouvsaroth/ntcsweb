<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display text is data, never an identifier. A lookup_values row is only
 * ever a stable `code`; every one of its human-readable names/descriptions
 * across every language lives here instead, so adding a new language later
 * (th, vi, fr...) is one more `languages` row plus these translation rows --
 * never a schema change to lookup_values itself. No tenant_id here,
 * deliberately -- it's already tenant-scoped transitively via
 * lookup_value_id (mirrors class_book/program_book's own shape).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lookup_value_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lookup_value_id')->constrained('lookup_values')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
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
