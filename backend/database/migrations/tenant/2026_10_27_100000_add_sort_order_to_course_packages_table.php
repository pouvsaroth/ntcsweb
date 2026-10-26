<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The order a school wants its course packages listed in, in every dropdown
 * and list (e.g. Word 1, Excel 2, ...). Packages with the same number fall
 * back to their name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
