<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Independent of show_on_website/show_in_popular (see those migrations) — a
 * package can be sold publicly without its video lessons being public, or
 * have its videos published while the package itself stays in-house-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->boolean('show_videos')->default(false)->after('show_in_popular');
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn('show_videos');
        });
    }
};
