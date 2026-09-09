<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an admin publish a package to the public website's course catalog
 * (see Public\CoursePackageController) without also having to make it
 * inactive/active — a package can be sold in-house but kept off the public
 * site, or vice versa, so this is deliberately independent of `is_active`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->boolean('show_on_website')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn('show_on_website');
        });
    }
};
