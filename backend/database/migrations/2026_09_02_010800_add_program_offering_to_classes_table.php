<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The nullable FK `classes`' own migration docblock already promised
 * ("adding that link later is one nullable foreign key, not a redesign").
 * `nullOnDelete`, matching teacher_id/classroom_id's existing nulling
 * behavior on this same table — losing an offering must not delete
 * class/enrollment/attendance history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->foreignId('program_offering_id')->nullable()->after('classroom_id')
                ->constrained('program_offerings')->nullOnDelete();

            $table->index(['tenant_id', 'program_offering_id']);
        });
    }

    public function down(): void
    {
        Schema::table('classes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_offering_id');
        });
    }
};
