<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A holiday's type — the `code` of a HOLIDAY_TYPE Base Data value (Public
 * Holiday, School Holiday, Additional Holiday, or one a school adds), like
 * staff.status holds a STAFF_STATUS code. Nullable: holidays saved before
 * this have none until someone edits them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->string('holiday_type', 50)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('holidays', function (Blueprint $table) {
            $table->dropColumn('holiday_type');
        });
    }
};
