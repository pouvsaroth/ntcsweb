<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. The highest-volume table this platform will hold (the
 * original spec targets millions of rows here), so every index below maps to
 * a query pattern that will actually run — nothing speculative.
 *
 * Like Teacher, `user_id` is nullable: many students (especially younger
 * ones) never get a portal login of their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->id();

            // No DB-level foreign key: `users` stays in the central
            // database while `students` lives in each school's own
            // per-tenant database (see database/migrations/tenant), and a
            // cross-database foreign key isn't possible in Postgres
            // regardless.
            $table->unsignedBigInteger('user_id')->nullable();

            $table->string('student_code', 32);
            $table->string('name');
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 10)->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('guardian_name')->nullable();
            $table->string('guardian_phone', 32)->nullable();
            $table->string('address')->nullable();
            $table->date('enrollment_date')->nullable();
            $table->string('status', 20)->default('active'); // active | graduated | withdrawn | inactive

            $table->timestamps();
            $table->softDeletes();

            $table->unique('student_code');
            // Admin list screens: "active students of this school", "newest
            // first" — the two shapes a students index endpoint always needs.
            $table->index('status');
            $table->index('created_at');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
