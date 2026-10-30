<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Staff > Languages, Job experience and Other Skills
 * (docs/hrm/staff-profile-tabs.md): many rows per staff member each.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('language', 100);
            // basic | intermediate | advanced | fluent | native
            $table->string('level', 20);
            $table->string('certificate', 150)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('staff_job_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('company', 150);
            $table->string('position', 150);
            $table->date('start_date')->nullable();
            // Empty while still working there.
            $table->date('end_date')->nullable();
            $table->string('responsibilities', 1000)->nullable();
            $table->string('reason_for_leaving', 255)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        Schema::create('staff_other_skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            $table->string('skill', 150);
            // basic | intermediate | advanced | expert
            $table->string('level', 20)->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        $db = DB::connection($this->getConnection());
        foreach (['staff_languages', 'staff_job_experiences', 'staff_other_skills'] as $table) {
            SchoolTables::secure($db, $table);
        }
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_other_skills');
        Schema::dropIfExists('staff_job_experiences');
        Schema::dropIfExists('staff_languages');
    }
};
