<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an admin re-order a room's tables/seats independently of `name`
 * (e.g. "COM-AIO-01" sorting first alphabetically isn't always the same as
 * the physical seat closest to the door) — same `sort_order` pattern as
 * LookupValue/HomeSlide/GalleryImage etc. Defaults to 0, same as those, so
 * every existing row starts equal and simply falls back to the table's
 * previous default order (by name) until someone sets a real value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('classroom_tables', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
