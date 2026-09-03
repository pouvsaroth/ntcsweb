<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class now links straight to an Academic Program — Program Offering
 * (Program + Study Mode + Academic Year) is being dropped entirely, see the
 * migration that follows this one. `nullOnDelete`, matching this column's
 * predecessor: losing a program must not delete class/enrollment/attendance
 * history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_offering_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('academic_program_id')->nullable()->after('classroom_id')
                ->constrained('academic_programs')->nullOnDelete();

            $table->index(['tenant_id', 'academic_program_id']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_program_id');
        });

        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('program_offering_id')->nullable()->after('classroom_id')
                ->constrained('program_offerings')->nullOnDelete();

            $table->index(['tenant_id', 'program_offering_id']);
        });
    }
};
