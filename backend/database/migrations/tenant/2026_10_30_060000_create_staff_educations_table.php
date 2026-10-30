<?php

use App\Support\Tenancy\SchoolTables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-owned. HRM > Staff > Education (docs/hrm/staff-education.md): a
 * staff member's schooling, many rows each — level, school or university,
 * major, years (no end year = still studying), note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_educations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('staff_id')->constrained('staff')->cascadeOnDelete();
            // high_school | diploma | associate | bachelor | master | doctorate | other
            $table->string('level', 20);
            $table->string('institution', 200);
            $table->string('major', 200)->nullable();
            $table->string('country', 100)->nullable();
            $table->unsignedSmallInteger('start_year')->nullable();
            $table->unsignedSmallInteger('end_year')->nullable();
            $table->string('note', 500)->nullable();
            $table->timestamps();

            $table->index('staff_id');
        });

        $db = DB::connection($this->getConnection());
        SchoolTables::secure($db, 'staff_educations');
        SchoolTables::grantAppRole($db);
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_educations');
    }
};
