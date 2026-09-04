<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The image shown on the public site's course card — see HomeSlide's
 * `image_path` for the same pattern (a tenant-scoped storage path, not a
 * full URL; resolved to one via CoursePackage::thumbnailUrl()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->string('thumbnail_path')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('course_packages', function (Blueprint $table) {
            $table->dropColumn('thumbnail_path');
        });
    }
};
