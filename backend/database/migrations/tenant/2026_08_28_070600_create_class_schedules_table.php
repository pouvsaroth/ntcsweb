<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The "study day" / "study time" of a class — one row per
 * weekly meeting slot, so a class that meets Mon/Wed/Fri has three rows here
 * rather than a single class row trying to hold multiple days.
 *
 * No DB-level foreign key on `class_id`: `classes` still lives in the
 * central database while this table lives in each school's own per-tenant
 * database (see database/migrations/tenant), and a cross-database foreign
 * key isn't possible in Postgres regardless.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_schedules', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('class_id');

            // ISO-8601: 1 = Monday ... 7 = Sunday.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('start_time');
            $table->time('end_time');

            $table->timestamps();

            // The same slot cannot be entered twice for one class.
            $table->unique(['class_id', 'day_of_week', 'start_time']);

            // "this class's weekly schedule" — the only real read pattern.
            $table->index('class_id');
        });

        DB::statement(
            'ALTER TABLE class_schedules ADD CONSTRAINT class_schedules_time_order_check CHECK (end_time > start_time)'
        );

        DB::statement(
            'ALTER TABLE class_schedules ADD CONSTRAINT class_schedules_day_of_week_check CHECK (day_of_week BETWEEN 1 AND 7)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('class_schedules');
    }
};
