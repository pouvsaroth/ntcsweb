<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Platform-global reference data (no tenant_id), same pattern as the
 * provinces/districts/communes/villages geography tables -- every school
 * shares the same language list; adding a new language later (th, vi, fr...)
 * is one more row here, never a schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10);
            $table->string('name');
            $table->string('native_name');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique('code');
        });

        // Only one language may be the default at a time -- enforced the
        // same way enrollments' "book_id XOR course_package_id" rule is:
        // a partial unique index, not application-level checking alone.
        DB::statement('CREATE UNIQUE INDEX languages_one_default ON languages (is_default) WHERE is_default = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
