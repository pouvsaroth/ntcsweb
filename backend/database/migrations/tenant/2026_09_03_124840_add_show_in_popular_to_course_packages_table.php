<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent of `show_on_website` (see the migration that added it) — a
 * package can be featured on the homepage's "Popular Programs" section
 * without also appearing in the full public course catalog, or vice versa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->boolean('show_in_popular')->default(false)->after('show_on_website');
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn('show_in_popular');
        });
    }
};
