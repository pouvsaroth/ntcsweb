<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive and nullable — every existing classroom keeps its free-text
 * `location` untouched; this just lets a classroom optionally point at a
 * real Building row instead (or alongside it, e.g. "Room 204" + "Main
 * Building").
 *
 * No DB-level foreign key: `buildings` lives in each school's own
 * per-tenant database (see database/migrations/tenant), and a
 * cross-database foreign key isn't possible in Postgres regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->unsignedBigInteger('building_id')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn('building_id');
        });
    }
};
